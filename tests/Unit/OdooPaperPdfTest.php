<?php

namespace Tests\Unit;

use App\Support\OdooPaperPdf;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Tests\TestCase;

class OdooPaperPdfTest extends TestCase
{
    public function test_a_cover_taller_than_a4_is_split_so_each_sheet_is_one_page(): void
    {
        $dir = storage_path('framework/cache/mpdf');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => [210, 594],
            'margin_top' => 0,
            'margin_bottom' => 0,
            'tempDir' => $dir,
        ]);
        $pdf->WriteHTML('<div style="height:560mm">غلاف طويل</div>');
        $tall = $pdf->Output('', Destination::STRING_RETURN);

        $pages = app(OdooPaperPdf::class);
        $this->assertSame(1, $pages->pageCount($tall));

        $paged = $pages->paginate($tall);
        $this->assertStringStartsWith('%PDF', $paged);
        $this->assertSame(2, $pages->pageCount($paged));
    }

    public function test_an_a4_cover_is_left_as_one_page(): void
    {
        $dir = storage_path('framework/cache/mpdf');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $pdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $dir]);
        $pdf->WriteHTML('<p>صفحة واحدة</p>');
        $single = $pdf->Output('', Destination::STRING_RETURN);

        $pages = app(OdooPaperPdf::class);
        $this->assertSame($single, $pages->paginate($single));
        $this->assertSame(1, $pages->pageCount($single));
    }

    public function test_continuation_pages_receive_their_own_cover_bands(): void
    {
        $dir = storage_path('framework/cache/mpdf');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $pdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $dir]);
        $pdf->WriteHTML('<p>صفحة أولى</p><pagebreak/><p>صفحة ثانية</p>');
        $binary = $pdf->Output('', Destination::STRING_RETURN);

        $cover = $dir.'/test-letterhead.png';
        $image = imagecreatetruecolor(210, 297);
        imagefilledrectangle($image, 0, 0, 209, 296, imagecolorallocate($image, 46, 14, 92));
        imagepng($image, $cover);
        imagedestroy($image);

        $pages = app(OdooPaperPdf::class);
        $stamped = $pages->stampContinuationCovers($binary, $cover);
        $this->assertStringStartsWith('%PDF', $stamped);
        $this->assertSame(2, $pages->pageCount($stamped));
        @unlink($cover);
    }

    public function test_letterhead_bands_keep_the_top_and_bottom_of_the_cover(): void
    {
        $image = imagecreatetruecolor(210, 297);
        imagefilledrectangle($image, 0, 0, 209, 296, imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 0, 0, 209, 57, imagecolorallocate($image, 10, 20, 80));
        imagefilledrectangle($image, 0, 249, 209, 296, imagecolorallocate($image, 200, 100, 20));
        ob_start();
        imagepng($image);
        $binary = (string) ob_get_clean();
        imagedestroy($image);

        $uris = app(OdooPaperPdf::class)->letterheadBandDataUris($binary);

        $this->assertIsArray($uris);
        $header = imagecreatefromstring((string) base64_decode(substr($uris['header'], strlen('data:image/png;base64,'))));
        $footer = imagecreatefromstring((string) base64_decode(substr($uris['footer'], strlen('data:image/png;base64,'))));
        $this->assertNotFalse($header);
        $this->assertNotFalse($footer);
        $this->assertSame(58, imagesy($header));
        $this->assertSame(48, imagesy($footer));
        $this->assertSame(10, imagecolorsforindex($header, imagecolorat($header, 4, 4))['red']);
        $this->assertSame(200, imagecolorsforindex($footer, imagecolorat($footer, 4, 4))['red']);
        imagedestroy($header);
        imagedestroy($footer);
    }

    public function test_stamp_keeps_the_source_page_full_width(): void
    {
        $dir = storage_path('framework/cache/mpdf');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
            'tempDir' => $dir,
        ]);
        $pdf->WriteHTML('<div style="position:absolute;top:80mm;right:2mm;width:40mm;text-align:right">وصف البند</div>');
        $binary = $pdf->Output('', Destination::STRING_RETURN);

        $cover = $dir.'/test-letterhead-wide.png';
        $image = imagecreatetruecolor(210, 297);
        imagefilledrectangle($image, 0, 0, 209, 296, imagecolorallocate($image, 255, 255, 255));
        imagepng($image, $cover);
        imagedestroy($image);

        $pages = app(OdooPaperPdf::class);
        $stamped = $pages->stampContinuationCovers($binary, $cover);
        $this->assertStringStartsWith('%PDF', $stamped);
        $this->assertSame(1, $pages->pageCount($stamped));
        $this->assertGreaterThan(strlen($binary) / 4, strlen($stamped));
        $tmp = $dir.'/stamp-size.pdf';
        file_put_contents($tmp, $stamped);
        $reader = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $dir,
        ]);
        $reader->setSourceFile($tmp);
        $size = $reader->getTemplateSize($reader->importPage(1));
        $this->assertEqualsWithDelta(210.0, (float) ($size['width'] ?? 0), 1.0);
        $this->assertEqualsWithDelta(297.0, (float) ($size['height'] ?? 0), 1.0);
        @unlink($tmp);
        @unlink($cover);
    }
}
