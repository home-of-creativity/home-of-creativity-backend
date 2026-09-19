<?php

namespace App\Support;

class ClientProfileValue
{
    /** @var list<string> */
    private const KEYBOARD_LABELS = ['طلب جديد', 'طلباتي', 'الدعم'];

    public static function isKeyboardLabel(?string $value): bool
    {
        if (! filled($value)) {
            return false;
        }

        $normalized = trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', '', $value));
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $normalized));

        return in_array($normalized, self::KEYBOARD_LABELS, true);
    }

    public static function looksLikePhone(?string $value): bool
    {
        if (! filled($value)) {
            return false;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';
        $compact = preg_replace('/\s+/u', '', $value) ?? '';

        return strlen($digits) >= 8 && strlen($digits) >= (int) round(strlen($compact) * 0.6);
    }

    public static function usableName(?string $value): ?string
    {
        if (! filled($value) || self::isKeyboardLabel($value) || self::looksLikePhone($value)) {
            return null;
        }

        return $value;
    }

    public static function usablePhone(?string $value): ?string
    {
        if (! filled($value) || self::isKeyboardLabel($value) || ! self::looksLikePhone($value)) {
            return null;
        }

        return $value;
    }

    public static function usableCompanyName(?string $value, ?string $telegramUserId = null): ?string
    {
        $usable = self::usableName($value);
        if ($usable === null) {
            return null;
        }

        $compact = preg_replace('/\s+/u', '', $usable) ?? '';
        if ($compact === '' || preg_match('/^tg[-_]/i', $compact) === 1) {
            return null;
        }

        if (filled($telegramUserId)) {
            $telegram = preg_replace('/\s+/u', '', (string) $telegramUserId) ?? '';
            if (mb_strlen($telegram) >= 4 && str_contains(mb_strtolower($compact), mb_strtolower($telegram))) {
                return null;
            }
        }

        return $usable;
    }
}
