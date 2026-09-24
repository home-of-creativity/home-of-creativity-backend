<?php

namespace Tests\Unit;

use App\Support\ReportPages;
use Tests\TestCase;

class ReportPagesTest extends TestCase
{
    public function test_it_keeps_legacy_page_breaks_with_a_header(): void
    {
        $parsed = ReportPages::parse('<p>أول</p><div class="page-break"></div><p>ثان</p>');

        $this->assertNull($parsed['cover']);
        $this->assertCount(2, $parsed['pages']);
        $this->assertTrue($parsed['pages'][0]['chrome']);
        $this->assertStringContainsString('أول', $parsed['pages'][0]['html']);
    }

    public function test_it_reads_cover_content_width_and_pages_without_chrome(): void
    {
        $parsed = ReportPages::parse(
            '<section class="hoc-cover" data-width="40"><p>غلاف</p></section>'
            .'<section class="hoc-page" data-chrome="0"><p>بلا</p></section>'
            .'<section class="hoc-page" data-chrome="1"><p>مع</p></section>'
        );

        $this->assertSame(40, $parsed['coverWidth']);
        $this->assertStringContainsString('غلاف', (string) $parsed['cover']);
        $this->assertFalse($parsed['pages'][0]['chrome']);
        $this->assertTrue($parsed['pages'][1]['chrome']);
    }

    public function test_the_pdf_view_skips_the_header_on_a_plain_page(): void
    {
        $html = view('reports.client', [
            'title' => 'تقرير',
            'header' => 'ترويسة',
            'footer' => 'تذييل',
            'body' => '<section class="hoc-cover" data-width="70"><p>نص الغلاف</p></section>'
                .'<section class="hoc-page" data-chrome="0"><p>صفحة بلا</p></section>',
            'cover' => 'cover.jpg',
        ])->render();

        $this->assertStringContainsString('نص الغلاف', $html);
        $this->assertStringContainsString('width: 70%', $html);
        $this->assertStringNotContainsString('ترويسة', $html);
    }

    public function test_the_pdf_view_prints_a_watermark_image(): void
    {
        $html = view('reports.client', [
            'title' => 'تقرير',
            'header' => '',
            'footer' => '',
            'body' => '<section class="hoc-mark" data-opacity="30" data-width="50"></section><section class="hoc-page" data-chrome="1"><p>متن</p></section>',
            'cover' => null,
            'watermark' => 'mark.webp',
        ])->render();

        $this->assertStringContainsString('class="watermark"', $html);
        $this->assertStringContainsString('mark.webp', $html);
        $this->assertStringContainsString('opacity: 0.3', $html);
    }
}
