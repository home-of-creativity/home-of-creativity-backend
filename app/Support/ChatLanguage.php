<?php

namespace App\Support;

class ChatLanguage
{
    /**
     * Arabic letters keep the chat in Arabic. Latin letters switch it to English.
     * Digits and punctuation leave the current language unchanged.
     */
    public static function detect(?string $text): ?string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }
        if (preg_match('/\p{Arabic}/u', $text) === 1) {
            return 'ar';
        }
        if (preg_match('/\p{Latin}/u', $text) === 1) {
            return 'en';
        }

        return null;
    }
}
