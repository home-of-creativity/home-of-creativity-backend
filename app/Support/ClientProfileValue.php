<?php

namespace App\Support;

class ClientProfileValue
{
    /** @var list<string> */
    private const KEYBOARD_LABELS = ['طلب جديد', 'طلباتي', 'الدعم'];

    /** @var list<string> */
    private const NO_EMAIL_ANSWERS = ['لا يوجد', 'لايوجد', 'لا يوجد بريد', 'لا', 'ما في', 'ما عندي', 'ما عندي بريد', 'ليس لدي', 'ليس لدي بريد', 'no', 'none', '-'];

    public const ACTIVITY_MAX_LENGTH = 60;

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

    public static function usableEmail(?string $value): ?string
    {
        $email = trim((string) $value);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return mb_strtolower($email);
    }

    public static function isNoEmailAnswer(?string $value): bool
    {
        $normalized = mb_strtolower(trim((string) $value));
        $normalized = trim((string) preg_replace('/[^\p{L}\p{N}\s-]+/u', '', $normalized));
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $normalized));

        return in_array($normalized, self::NO_EMAIL_ANSWERS, true);
    }

    public static function usableActivity(?string $value): ?string
    {
        $activity = trim((string) preg_replace('/\s+/u', ' ', (string) $value));
        if ($activity === ''
            || self::isKeyboardLabel($activity)
            || self::looksLikePhone($activity)
            || mb_strlen($activity) < 2
            || mb_strlen($activity) > self::ACTIVITY_MAX_LENGTH
            || preg_match('/\p{L}/u', $activity) !== 1) {
            return null;
        }

        return $activity;
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
