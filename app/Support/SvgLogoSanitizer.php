<?php

namespace App\Support;

final class SvgLogoSanitizer
{
    public static function cleanPath(string $absolutePath): void
    {
        if (! is_file($absolutePath) || ! str_ends_with(strtolower($absolutePath), '.svg')) {
            return;
        }

        $contents = file_get_contents($absolutePath);
        if ($contents === false || $contents === '') {
            return;
        }

        $cleaned = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $contents) ?? $contents;
        $cleaned = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/javascript\s*:/i', '', $cleaned) ?? $cleaned;

        if ($cleaned !== $contents) {
            file_put_contents($absolutePath, $cleaned);
        }
    }
}
