<?php

namespace App\Support;

use Mpdf\Config\ConfigVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Throwable;

/**
 * The Odoo letterhead is one A4 cover. When the report engine emits a taller first
 * page, that cover stretches onto the next sheets. This splits every page back onto
 * A4 so each sheet has its own cover, not a continuation of page 1.
 */
class OdooPaperPdf
{
    public const A4_WIDTH_MM = 210.0;

    public const A4_HEIGHT_MM = 297.0;

    private const TALL_SLACK_MM = 8.0;

    public function paginate(string $binary): string
    {
        if ($binary === '' || ! str_starts_with($binary, '%PDF')) {
            return $binary;
        }

        $source = null;
        try {
            $source = $this->tempPdf($binary);
            $reader = $this->mpdf();
            $pages = $reader->setSourceFile($source);
            if ($pages < 1) {
                return $binary;
            }

            $tall = false;
            for ($index = 1; $index <= $pages; $index++) {
                $size = $reader->getTemplateSize($reader->importPage($index));
                if ((float) ($size['height'] ?? 0) > self::A4_HEIGHT_MM + self::TALL_SLACK_MM) {
                    $tall = true;
                    break;
                }
            }
            if (! $tall) {
                return $binary;
            }

            $out = $this->mpdf();
            $out->setSourceFile($source);
            for ($index = 1; $index <= $pages; $index++) {
                $template = $out->importPage($index);
                $size = $out->getTemplateSize($template);
                $width = (float) ($size['width'] ?? self::A4_WIDTH_MM);
                $height = (float) ($size['height'] ?? self::A4_HEIGHT_MM);
                if ($height <= self::A4_HEIGHT_MM + self::TALL_SLACK_MM) {
                    $out->AddPageByArray([
                        'orientation' => $width > $height ? 'L' : 'P',
                        'sheet-size' => [$width, $height],
                    ]);
                    $out->useTemplate($template, 0, 0, $width, $height);

                    continue;
                }

                $slices = (int) max(1, ceil($height / self::A4_HEIGHT_MM - 0.02));
                for ($slice = 0; $slice < $slices; $slice++) {
                    $out->AddPageByArray([
                        'orientation' => 'P',
                        'sheet-size' => [self::A4_WIDTH_MM, self::A4_HEIGHT_MM],
                    ]);
                    $out->useTemplate($template, 0, -($slice * self::A4_HEIGHT_MM), $width, $height);
                }
            }

            $paged = $out->Output('', Destination::STRING_RETURN);

            return is_string($paged) && str_starts_with($paged, '%PDF') ? $paged : $binary;
        } catch (Throwable) {
            return $binary;
        } finally {
            if (is_string($source) && is_file($source)) {
                @unlink($source);
            }
        }
    }

    /**
     * Paint the hummingbird header and contact bar on every sheet so later pages
     * match page 1 and line items cannot sit on the cover footer.
     */
    public function stampContinuationCovers(string $binary, string $letterheadPng): string
    {
        if ($binary === '' || ! str_starts_with($binary, '%PDF') || ! is_file($letterheadPng)) {
            return $binary;
        }

        $source = null;
        $header = null;
        $footer = null;
        try {
            $bands = $this->letterheadBands($letterheadPng);
            if ($bands === null) {
                return $binary;
            }
            [$header, $footer] = $bands;

            $source = $this->tempPdf($binary);
            $out = $this->mpdf();
            $out->SetAutoPageBreak(false);
            $pages = $out->setSourceFile($source);
            if ($pages < 1) {
                return $binary;
            }

            $top = OdooPaperLimits::LETTERHEAD_MARGIN_TOP;
            $bottom = OdooPaperLimits::LETTERHEAD_MARGIN_BOTTOM;
            for ($index = 1; $index <= $pages; $index++) {
                $template = $out->importPage($index);
                $out->AddPageByArray([
                    'orientation' => 'P',
                    'sheet-size' => [self::A4_WIDTH_MM, self::A4_HEIGHT_MM],
                ]);
                $out->useTemplate($template, 0, 0, self::A4_WIDTH_MM, self::A4_HEIGHT_MM);
                $out->SetFillColor(255, 255, 255);
                $out->Rect(0, 0, self::A4_WIDTH_MM, $top, 'F');
                $out->Rect(0, self::A4_HEIGHT_MM - $bottom, self::A4_WIDTH_MM, $bottom, 'F');
                $out->Image($header, 0, 0, self::A4_WIDTH_MM, $top, 'png');
                $out->Image($footer, 0, self::A4_HEIGHT_MM - $bottom, self::A4_WIDTH_MM, $bottom, 'png');
            }

            $stamped = $out->Output('', Destination::STRING_RETURN);

            return is_string($stamped) && str_starts_with($stamped, '%PDF') ? $stamped : $binary;
        } catch (Throwable) {
            return $binary;
        } finally {
            foreach ([$source, $header, $footer] as $path) {
                if (is_string($path) && is_file($path) && ! str_ends_with($path, 'hoc-letterhead.png')) {
                    @unlink($path);
                }
            }
        }
    }

    /**
     * Header and footer slices of the cover, as PNG data URIs the report can print
     * at the paper margins. A full-page image inside the header paints over the lines.
     *
     * @return array{header: string, footer: string}|null
     */
    public function letterheadBandDataUris(string $binary): ?array
    {
        if ($binary === '') {
            return null;
        }

        $path = tempnam($this->tempDir(), 'hoc-cover-');
        if (! is_string($path) || $path === '') {
            return null;
        }
        file_put_contents($path, $binary);
        $bands = $this->letterheadBands($path);
        @unlink($path);
        if ($bands === null) {
            return null;
        }

        [$header, $footer] = $bands;
        $uris = [
            'header' => 'data:image/png;base64,'.base64_encode((string) file_get_contents($header)),
            'footer' => 'data:image/png;base64,'.base64_encode((string) file_get_contents($footer)),
        ];
        @unlink($header);
        @unlink($footer);

        return $uris;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function letterheadBands(string $letterheadPng): ?array
    {
        $image = @imagecreatefromstring((string) file_get_contents($letterheadPng));
        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width < 8 || $height < 8) {
            imagedestroy($image);

            return null;
        }

        $topPx = max(1, (int) round($height * OdooPaperLimits::LETTERHEAD_MARGIN_TOP / self::A4_HEIGHT_MM));
        $bottomPx = max(1, (int) round($height * OdooPaperLimits::LETTERHEAD_MARGIN_BOTTOM / self::A4_HEIGHT_MM));
        $header = $this->cropPng($image, 0, 0, $width, $topPx);
        $footer = $this->cropPng($image, 0, $height - $bottomPx, $width, $bottomPx);
        imagedestroy($image);
        if ($header === null || $footer === null) {
            return null;
        }

        return [$header, $footer];
    }

    private function cropPng(\GdImage $image, int $x, int $y, int $width, int $height): ?string
    {
        $band = imagecreatetruecolor($width, $height);
        if ($band === false) {
            return null;
        }
        imagealphablending($band, false);
        imagesavealpha($band, true);
        imagecopy($band, $image, 0, 0, $x, $y, $width, $height);
        $path = tempnam($this->tempDir(), 'hoc-band-').'.png';
        $ok = imagepng($band, $path);
        imagedestroy($band);

        return $ok ? $path : null;
    }

    public function pageCount(string $binary): int
    {
        if ($binary === '' || ! str_starts_with($binary, '%PDF')) {
            return 0;
        }

        $source = null;
        try {
            $source = $this->tempPdf($binary);

            return $this->mpdf()->setSourceFile($source);
        } catch (Throwable) {
            return 0;
        } finally {
            if (is_string($source) && is_file($source)) {
                @unlink($source);
            }
        }
    }

    private function mpdf(): Mpdf
    {
        $defaults = (new ConfigVariables)->getDefaults();

        return new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
            'tempDir' => $this->tempDir(),
            'fontDir' => $defaults['fontDir'],
        ]);
    }

    private function tempPdf(string $binary): string
    {
        $path = tempnam($this->tempDir(), 'odoo-paper-');
        if (! is_string($path) || $path === '') {
            throw new \RuntimeException('Could not create a temporary PDF.');
        }
        $named = $path.'.pdf';
        @rename($path, $named);
        file_put_contents($named, $binary);

        return $named;
    }

    private function tempDir(): string
    {
        $dir = storage_path('framework/cache/mpdf');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }
}
