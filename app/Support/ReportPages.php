<?php

namespace App\Support;

class ReportPages
{
    /**
     * @return array{cover: ?string, coverWidth: int, mark: bool, markOpacity: int, markWidth: int, pages: list<array{html: string, chrome: bool}>}
     */
    public static function parse(string $body): array
    {
        if (! str_contains($body, 'hoc-page') && ! str_contains($body, 'hoc-cover') && ! str_contains($body, 'hoc-mark')) {
            return [
                'cover' => null,
                'coverWidth' => 100,
                'mark' => false,
                'markOpacity' => 18,
                'markWidth' => 42,
                'pages' => self::legacyPages($body),
            ];
        }

        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"><div id="hoc-root">'.$body.'</div>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('hoc-root');
        $cover = null;
        $coverWidth = 100;
        $mark = false;
        $markOpacity = 18;
        $markWidth = 42;
        $pages = [];

        if ($root instanceof \DOMElement) {
            foreach ($root->childNodes as $node) {
                if (! $node instanceof \DOMElement || strcasecmp($node->tagName, 'section') !== 0) {
                    continue;
                }
                $class = ' '.$node->getAttribute('class').' ';
                if (str_contains($class, ' hoc-cover ')) {
                    $cover = self::innerHtml($node);
                    $width = (int) $node->getAttribute('data-width');
                    $coverWidth = max(15, min(100, $width > 0 ? $width : 100));
                }
                if (str_contains($class, ' hoc-mark ')) {
                    $mark = true;
                    $opacity = (int) $node->getAttribute('data-opacity');
                    $width = (int) $node->getAttribute('data-width');
                    $markOpacity = max(5, min(80, $opacity > 0 ? $opacity : 18));
                    $markWidth = max(15, min(80, $width > 0 ? $width : 42));
                }
                if (str_contains($class, ' hoc-page ')) {
                    $pages[] = [
                        'html' => self::innerHtml($node),
                        'chrome' => $node->getAttribute('data-chrome') !== '0',
                    ];
                }
            }
        }

        if ($pages === []) {
            $pages[] = ['html' => '<p></p>', 'chrome' => true];
        }

        return [
            'cover' => $cover,
            'coverWidth' => $coverWidth,
            'mark' => $mark,
            'markOpacity' => $markOpacity,
            'markWidth' => $markWidth,
            'pages' => $pages,
        ];
    }

    /**
     * @return list<array{html: string, chrome: bool}>
     */
    private static function legacyPages(string $body): array
    {
        $parts = preg_split('/<div class="page-break"><\/div>/i', $body) ?: [];
        $pages = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $pages[] = ['html' => $part, 'chrome' => true];
        }

        return $pages === [] ? [['html' => '<p></p>', 'chrome' => true]] : $pages;
    }

    private static function innerHtml(\DOMElement $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?? '';
        }

        return trim($html) === '' ? '<p></p>' : $html;
    }
}
