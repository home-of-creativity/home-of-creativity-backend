<?php

namespace App\Support;

/** A report title that is safe to store and to use as a download name. */
class ReportTitle
{
    /**
     * Replace characters a file name cannot contain. The rest of the title stays.
     * An empty result means the title was only those characters, and must not be stored.
     */
    public static function clean(?string $title): string
    {
        $text = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], ' ', (string) $title);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
