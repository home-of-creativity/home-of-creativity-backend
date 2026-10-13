<?php

namespace Tests\Feature;

use App\Services\OdooClient;
use App\Support\OdooPaperPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Tests\TestCase;

class OdooPaperLetterheadTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<mixed> */
    private array $writes = [];

    /** @var list<mixed> */
    private array $views = [];

    public function test_download_splits_a_stretched_cover_and_leaves_the_odoo_layout_alone(): void
    {
        $this->fakeOdoo();

        $binary = app(OdooClient::class)->downloadSaleOrderPdf(880);

        $this->assertNotNull($binary);
        $this->assertStringStartsWith('%PDF', (string) $binary);
        $this->assertSame(2, app(OdooPaperPdf::class)->pageCount((string) $binary));
        // The layout is written only by odoo:report-layout, so a download never changes it.
        $this->assertSame([], $this->writes);
        $this->assertSame([], $this->views);
    }

    public function test_the_report_layout_command_writes_paper_cover_lines_and_letter(): void
    {
        $this->fakeOdoo();

        $this->artisan('odoo:report-layout')
            ->expectsOutput('paper: ok')
            ->expectsOutput('cover: ok')
            ->expectsOutput('lines: ok')
            ->expectsOutput('letter: ok')
            ->assertSuccessful();

        $writes = $this->writes;
        $views = $this->views;
        $this->assertNotEmpty($writes);
        $this->assertSame('A4', $writes[0][1]['format'] ?? null);
        $this->assertSame('Portrait', $writes[0][1]['orientation'] ?? null);
        $this->assertSame(0, $writes[0][1]['page_height'] ?? null);
        $this->assertSame(0, $writes[0][1]['page_width'] ?? null);
        $this->assertSame(58, $writes[0][1]['margin_top'] ?? null);
        $this->assertSame(48, $writes[0][1]['margin_bottom'] ?? null);
        $this->assertSame(0, $writes[0][1]['margin_left'] ?? null);
        $this->assertSame(0, $writes[0][1]['margin_right'] ?? null);
        $this->assertArrayNotHasKey('disable_shrinking', $writes[0][1] ?? []);
        $this->assertTrue($writes[1][1]['disable_shrinking'] ?? false);
        // wkhtmltopdf reads the header as 0mm tall; spacing equal to the margin puts it at the page top.
        $this->assertSame(58, $writes[0][1]['header_spacing'] ?? null);
        $this->assertSame(90, $writes[0][1]['dpi'] ?? null);

        $byName = [];
        foreach ($views as $payload) {
            $vals = $payload[0][0] ?? [];
            $byName[(string) ($vals['name'] ?? '')] = (string) ($vals['arch'] ?? '');
        }
        $cover = $byName['HOC paged letterhead'] ?? '';
        $this->assertStringContainsString('data:image/png;base64,', $cover);
        // Sized for dpi 90 so they print at exactly 58mm and 48mm.
        $this->assertStringContainsString('height: 54.38mm', $cover);
        $this->assertStringContainsString('height: 45mm', $cover);
        $this->assertStringContainsString('.o_body_html .header{padding-top:0 !important;}', $cover);
        $this->assertStringContainsString('direction: ltr', $cover);
        $this->assertStringNotContainsString('background-size', $cover);
        $this->assertStringContainsString("split('\\n')[1:]", $byName['HOC split long lines (quotation)'] ?? '');
        $this->assertStringContainsString("split('\\n')[1:]", $byName['HOC split long lines (invoice)'] ?? '');
        // The greeting sits on the same line as the company.
        $this->assertStringContainsString('doc.partner_id.name"/>، تحية طيبة وبعد.</p>', $byName['HOC quotation letter'] ?? '');
    }

    private function fakeOdoo(): void
    {
        config([
            'services.odoo.enabled' => true,
            'services.odoo.url' => 'https://odoo.test',
            'services.odoo.db' => 'hoc',
            'services.odoo.username' => 'admin',
            'services.odoo.api_key' => 'secret-key',
            'services.odoo.use_json2' => false,
        ]);

        $writes = &$this->writes;
        $views = &$this->views;
        $cover = imagecreatetruecolor(210, 297);
        imagefilledrectangle($cover, 0, 0, 209, 296, imagecolorallocate($cover, 255, 255, 255));
        ob_start();
        imagepng($cover);
        $coverPng = base64_encode((string) ob_get_clean());
        imagedestroy($cover);
        $dir = storage_path('framework/cache/mpdf');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $pdf = new Mpdf(['mode' => 'utf-8', 'format' => [210, 594], 'margin_top' => 0, 'margin_bottom' => 0, 'tempDir' => $dir]);
        $pdf->WriteHTML('<div style="height:560mm">غطاء</div>');
        $tall = $pdf->Output('', Destination::STRING_RETURN);

        Http::preventStrayRequests();
        Http::fake([
            'https://odoo.test/jsonrpc' => function (Request $request) use (&$writes, &$views, $coverPng) {
                $params = $request->data()['params'] ?? [];
                if (($params['service'] ?? '') === 'common') {
                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2]);
                }
                $args = $params['args'] ?? [];
                $model = (string) ($args[3] ?? '');
                $method = (string) ($args[4] ?? '');
                $payload = $args[5] ?? [];
                if ($model === 'report.paperformat' && $method === 'write') {
                    $writes[] = $payload;
                }
                if ($model === 'ir.ui.view' && $method === 'create') {
                    $views[] = $payload;
                }

                $result = match (true) {
                    $model === 'ir.actions.report' && $method === 'search_read' => [[
                        'id' => 12, 'paperformat_id' => [4, 'European A4'],
                    ]],
                    $model === 'report.paperformat' && $method === 'search_read' => [],
                    $model === 'report.paperformat' && $method === 'create' => 9,
                    $model === 'ir.model.data' && $method === 'search_read' => [['id' => 1, 'res_id' => 211]],
                    $model === 'res.company' && $method === 'search_read' => [[
                        'id' => 1,
                        'layout_background_image' => $coverPng,
                    ]],
                    // The report documents the line and letter views inherit from.
                    $model === 'ir.ui.view' && $method === 'search_read' => str_contains(json_encode($payload) ?: '', '"key"') ? [['id' => 500]] : [],
                    $model === 'ir.ui.view' && $method === 'create' => 99,
                    $model === 'sale.order' && $method === 'get_portal_url' => '/my/orders/880?access_token=t',
                    default => true,
                };

                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
            },
            'https://odoo.test/my/orders/*' => Http::response($tall, 200, ['Content-Type' => 'application/pdf']),
        ]);
    }
}
