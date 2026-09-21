<?php

namespace App\Support;

use Illuminate\Support\Str;

class LegalHtml
{
    /**
     * Allow a small HTML subset for legal sections edited in the dashboard.
     */
    public static function clean(string $html): string
    {
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;
        $html = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html) ?? $html;
        $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;

        $cleaned = trim(strip_tags($html, [
            'section', 'h2', 'h3', 'h4', 'p', 'ul', 'ol', 'li', 'a',
            'strong', 'em', 'b', 'i', 'br', 'blockquote', 'hr',
            'span', 'small', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
        ]));

        $cleaned = preg_replace_callback(
            '/<a\s+([^>]*?)>/i',
            function (array $match): string {
                $attrs = $match[1];
                if (! preg_match('/href\s*=\s*(["\'])(.*?)\1/i', $attrs, $hrefMatch)) {
                    return '<a>';
                }

                $href = trim(html_entity_decode($hrefMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (! preg_match('/^(https?:\/\/|mailto:|#|\/)/i', $href)) {
                    return '<a>';
                }

                return '<a href="'.e($href).'" rel="noopener noreferrer">';
            },
            $cleaned,
        ) ?? $cleaned;

        return $cleaned;
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return list<array{id: string, heading_ar: string, heading_en: string, html_ar: string, html_en: string}>
     */
    public static function cleanSections(array $sections): array
    {
        $cleaned = [];

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $headingAr = trim((string) ($section['heading_ar'] ?? ''));
            $headingEn = trim((string) ($section['heading_en'] ?? ''));
            $id = strtolower(trim((string) ($section['id'] ?? '')));
            $id = preg_replace('/[^a-z0-9\-]+/', '-', $id) ?: '';
            if ($id === '' || $id === '-') {
                $id = Str::slug($headingEn) ?: Str::slug($headingAr) ?: 'section';
            }

            $base = $id;
            $n = 2;
            $used = array_column($cleaned, 'id');
            while (in_array($id, $used, true)) {
                $id = $base.'-'.$n;
                $n++;
            }

            $cleaned[] = [
                'id' => $id,
                'heading_ar' => $headingAr,
                'heading_en' => $headingEn,
                'html_ar' => self::clean((string) ($section['html_ar'] ?? '')),
                'html_en' => self::clean((string) ($section['html_en'] ?? '')),
            ];
        }

        return $cleaned;
    }
}
