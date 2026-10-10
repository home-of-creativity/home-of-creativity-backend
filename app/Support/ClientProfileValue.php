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

        if (self::embedsTelegramId($compact, $telegramUserId)) {
            return null;
        }

        return $usable;
    }

    /**
     * A clear company name is saved. A sentence is sent to the model. A vague reply is refused.
     *
     * @return array{name: ?string, review: bool}
     */
    public static function judgeCompanyName(?string $value, ?string $telegramUserId = null): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $value));
        if ($text === '' || self::isKeyboardLabel($text) || self::looksLikePhone($text)) {
            return ['name' => null, 'review' => false];
        }

        $compact = preg_replace('/\s+/u', '', $text) ?? '';
        if ($compact === '' || preg_match('/^tg[-_]/i', $compact) === 1 || self::embedsTelegramId($compact, $telegramUserId)) {
            return ['name' => null, 'review' => false];
        }

        $candidate = $text;
        $marked = false;
        if (preg_match('/(?:company(?:\s+name)?|اسم\s*الشركة|شركتنا|شركتي|الشركة|شركة)\s*[:\-ـ]?\s*(?:اسمها|اسمه|هي|هو)?\s*(.+)$/ui', $text, $match) === 1) {
            $marked = true;
            $candidate = self::trimCompanyEdges($match[1]);
            if (preg_match('/^(?:company|الشركة|شركة|شركتي|شركتنا)\s+(.+)$/ui', $candidate, $again) === 1) {
                $candidate = self::trimCompanyEdges($again[1]);
            }
        }

        if ($marked && preg_match('/^(.*)(?:company(?:\s+name)?|اسم\s*الشركة|شركتنا|شركتي|الشركة|شركة)/ui', $text, $head) === 1) {
            $before = trim($head[1]);
            if ($before !== '' && preg_match('/\p{L}/u', $before) === 1) {
                return ['name' => null, 'review' => true];
            }
        }

        if (self::isClearCompany($candidate) && self::usableCompanyName($candidate, $telegramUserId) !== null) {
            return ['name' => $candidate, 'review' => false];
        }

        $review = mb_strlen($text) >= 12 && preg_match('/\p{L}/u', $text) === 1 && ! self::isVagueCompany($text);

        return ['name' => null, 'review' => $review];
    }

    private static function embedsTelegramId(string $compact, ?string $telegramUserId): bool
    {
        if (! filled($telegramUserId)) {
            return false;
        }

        $telegram = preg_replace('/\s+/u', '', (string) $telegramUserId) ?? '';

        return mb_strlen($telegram) >= 4 && str_contains(mb_strtolower($compact), mb_strtolower($telegram));
    }

    private static function isClearCompany(string $name): bool
    {
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60 || self::isVagueCompany($name)) {
            return false;
        }
        if (preg_match('/\p{L}/u', $name) !== 1) {
            return false;
        }
        if (preg_match('/[؟?!]|(?:^|\s)(?:بس|لسا|لسه|بعدين|بدي|بدنا|والله|متخصص\p{L}*|نشتغل|ما|مو|مش)(?:\s|$)/u', $name) === 1) {
            return false;
        }

        $words = preg_split('/\s+/u', $name) ?: [];

        return count($words) >= 1 && count($words) <= 6;
    }

    private static function isVagueCompany(string $name): bool
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', '', $name)));
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $normalized));
        if (in_array($normalized, [
            'شركة', 'الشركة', 'شركتي', 'شركتنا', 'اسم الشركة', 'محل', 'المحل', 'مؤسسة', 'المؤسسة',
            'ما بعرف', 'مابعرف', 'مش عارف', 'مو عارف', 'لا اعرف', 'لا أعرف', 'ما في', 'مافي',
            'لا يوجد', 'لايوجد', 'بعدين', 'لاحقا', 'مو هلق', 'هلق', 'هلأ', 'تمام', 'اوكي', 'أوكي', 'حاضر',
            'مرحبا', 'أهلا', 'اهلا', 'هلا', 'نعم', 'لا', 'ok', 'none', 'company', 'idk',
        ], true)) {
            return true;
        }

        return preg_match('/^(?:اسم\s+)?(?:ال)?(?:شركة|شركتي|شركتنا|محل|محلي|مؤسسة)$/u', $normalized) === 1;
    }

    private static function trimCompanyEdges(string $value): string
    {
        $value = trim($value);

        return trim((string) preg_replace('/^[\s.:،,\-ـ]+|[\s.:،,\-ـ]+$/u', '', $value));
    }
}
