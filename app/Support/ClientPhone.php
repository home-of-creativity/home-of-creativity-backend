<?php

namespace App\Support;

/**
 * Turns a typed phone into +countrycode and national number.
 * A local number waits until the country is known.
 */
class ClientPhone
{
    /**
     * Dial code, shortest national length, longest national length, names, and words found
     * inside a sentence (nationalities).
     *
     * @var list<array{0: string, 1: int, 2: int, 3: list<string>, 4?: list<string>}>
     */
    private const COUNTRIES = [
        ['1', 10, 10, ['امريكا', 'أمريكا', 'الولايات المتحدة', 'usa', 'us', 'america', 'united states', 'كندا', 'canada']],
        ['7', 10, 10, ['روسيا', 'russia']],
        ['20', 10, 10, ['مصر', 'egypt'], ['مصري', 'egyptian']],
        ['30', 10, 10, ['اليونان', 'greece']],
        ['33', 9, 9, ['فرنسا', 'france']],
        ['34', 9, 9, ['اسبانيا', 'إسبانيا', 'spain']],
        ['39', 9, 10, ['ايطاليا', 'إيطاليا', 'italy']],
        ['44', 10, 10, ['بريطانيا', 'انجلترا', 'إنجلترا', 'المملكة المتحدة', 'uk', 'britain', 'england', 'united kingdom']],
        ['49', 10, 11, ['المانيا', 'ألمانيا', 'germany']],
        ['86', 11, 11, ['الصين', 'china']],
        ['90', 10, 10, ['تركيا', 'turkey'], ['تركي', 'turkish']],
        ['91', 10, 10, ['الهند', 'india']],
        ['92', 10, 10, ['باكستان', 'pakistan']],
        ['98', 10, 10, ['ايران', 'إيران', 'iran']],
        ['212', 9, 9, ['المغرب', 'morocco']],
        ['213', 9, 9, ['الجزائر', 'algeria']],
        ['216', 8, 8, ['تونس', 'tunisia']],
        ['218', 9, 9, ['ليبيا', 'libya']],
        ['249', 9, 9, ['السودان', 'sudan']],
        ['961', 7, 8, ['لبنان', 'lebanon'], ['لبناني', 'lebanese']],
        ['962', 9, 9, ['الاردن', 'الأردن', 'jordan'], ['اردني', 'أردني', 'jordanian']],
        ['963', 8, 9, ['سوريا', 'سورية', 'syria'], ['سوري', 'الشام', 'syrian']],
        ['964', 10, 10, ['العراق', 'iraq'], ['عراقي', 'iraqi']],
        ['965', 8, 8, ['الكويت', 'kuwait']],
        ['966', 9, 9, ['السعودية', 'السعوديه', 'saudi', 'saudi arabia'], ['سعودي']],
        ['967', 9, 9, ['اليمن', 'yemen']],
        ['968', 8, 8, ['عمان', 'عُمان', 'oman']],
        ['970', 9, 9, ['فلسطين', 'palestine']],
        ['971', 9, 9, ['الامارات', 'الإمارات', 'الإمارات العربية المتحدة', 'uae', 'emirates'], ['اماراتي', 'إماراتي', 'دبي', 'dubai']],
        ['972', 9, 9, ['اسرائيل', 'إسرائيل', 'israel']],
        ['973', 8, 8, ['البحرين', 'bahrain']],
        ['974', 8, 8, ['قطر', 'qatar']],
    ];

    public static function wantsCurrentNumber(?string $value): bool
    {
        $text = self::normalizeWords($value);
        if ($text === '' || preg_match('/\d{6,}/', $text) === 1) {
            return false;
        }

        return in_array($text, [
            'خذ رقمي الحالي',
            'خذ رقمي',
            'رقمي الحالي',
            'الرقم الحالي',
            'نفس رقمي',
            'نفس الرقم',
            'رقم الواتس',
            'رقم الواتساب',
            'رقم واتسابي',
            'خذ رقم الواتس',
            'خذ رقم الواتساب',
            'use my current number',
            'take my number',
            'my whatsapp number',
            'current number',
        ], true);
    }

    /**
     * @return array{status: 'current'|'ready'|'country'|'invalid', phone?: string, national?: string}
     */
    public static function interpret(?string $value): array
    {
        if (self::wantsCurrentNumber($value)) {
            return ['status' => 'current'];
        }

        $digits = self::digits($value);
        if ($digits === null) {
            return ['status' => 'invalid'];
        }

        $compact = self::compact($value);
        $explicit = str_starts_with($compact, '+') || str_starts_with($compact, '00');
        if (str_starts_with($compact, '00')) {
            $digits = substr($digits, 2);
        }
        $trunk = str_starts_with($digits, '0');

        if ($explicit) {
            $international = self::matchInternational($digits);

            return $international === null
                ? ['status' => 'invalid']
                : ['status' => 'ready', 'phone' => $international];
        }

        if (! $trunk) {
            $international = self::matchInternational($digits);
            if ($international !== null) {
                return ['status' => 'ready', 'phone' => $international];
            }
        }

        $national = ltrim($digits, '0');
        if (strlen($national) < 6 || strlen($national) > 12) {
            return ['status' => 'invalid'];
        }

        return ['status' => 'country', 'national' => $national];
    }

    /**
     * International digits of a stored phone, as WhatsApp sends and receives them. A local
     * number without a country code is Syrian. Empty when the phone cannot be read.
     */
    public static function whatsappDigits(?string $stored): string
    {
        $read = self::interpret($stored);
        if (($read['status'] ?? '') === 'ready') {
            return preg_replace('/\D+/', '', (string) $read['phone']) ?? '';
        }
        if (($read['status'] ?? '') === 'country') {
            return '963'.$read['national'];
        }

        return '';
    }

    public static function fromCallerDigits(?string $digits): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $digits) ?? '';

        return $digits === '' ? null : self::matchInternational($digits);
    }

    public static function applyCountry(?string $national, ?string $country): ?string
    {
        $nationalDigits = ltrim(self::digits($national) ?? '', '0');
        $code = self::dialFor($country);
        if ($code === null || $nationalDigits === '') {
            return null;
        }

        return self::matchInternational($code.$nationalDigits);
    }

    public static function dialFor(?string $country): ?string
    {
        $digits = self::digits($country);
        $compact = self::compact($country);
        if ($digits !== null && (str_starts_with($compact, '+') || str_starts_with($compact, '00') || preg_match('/^\d+$/', $compact) === 1)) {
            foreach (self::countriesByCodeLength() as $row) {
                if ($digits === $row[0]) {
                    return $row[0];
                }
            }
        }

        $name = self::normalizeWords($country);
        if ($name === '') {
            return null;
        }

        foreach (self::COUNTRIES as $row) {
            foreach ($row[3] as $alias) {
                if (self::normalizeWords($alias) === $name) {
                    return $row[0];
                }
            }
        }

        // A sentence: «من سوريا», «رقم سوري», «the code is +961».
        if (preg_match('/(?:\+|00)(\d{1,3})\b/', self::asciiDigits((string) $country), $code) === 1) {
            foreach (self::countriesByCodeLength() as $row) {
                if ($code[1] === $row[0]) {
                    return $row[0];
                }
            }
        }
        $padded = ' '.$name.' ';
        foreach (self::COUNTRIES as $row) {
            foreach ([...$row[3], ...($row[4] ?? [])] as $alias) {
                $word = self::normalizeWords($alias);
                if (mb_strlen($word) >= 3 && str_contains($padded, ' '.$word.' ')) {
                    return $row[0];
                }
            }
        }

        return null;
    }

    /** The country name for a dial code, for the question that confirms it. */
    public static function countryName(string $code): ?string
    {
        foreach (self::COUNTRIES as $row) {
            if ($row[0] === $code) {
                return $row[3][0];
            }
        }

        return null;
    }

    /**
     * The digits a dashboard search looks for when it is a phone: without +, 00, the leading
     * 0 or a full number's country code, so 0944…, +963 944… and ٠٩٤٤… find the same client.
     * Null when the search has letters or fewer than 3 digits.
     */
    public static function searchDigits(?string $search): ?string
    {
        $ascii = trim(self::asciiDigits($search));
        if ($ascii === '' || preg_match('/^[\d\s+\-().]+$/', $ascii) !== 1) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $ascii) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        foreach (self::countriesByCodeLength() as [$code, $min, $max]) {
            $national = substr($digits, strlen($code));
            if (str_starts_with($digits, $code) && strlen($national) >= $min && strlen($national) <= $max) {
                $digits = $national;
                break;
            }
        }
        $digits = ltrim($digits, '0');

        return strlen($digits) >= 3 ? $digits : null;
    }

    public static function matchInternational(string $digits): ?string
    {
        foreach (self::countriesByCodeLength() as $row) {
            [$code, $min, $max] = $row;
            if (! str_starts_with($digits, $code)) {
                continue;
            }
            $national = substr($digits, strlen($code));
            $length = strlen($national);
            if ($length >= $min && $length <= $max) {
                return '+'.$digits;
            }
        }

        return null;
    }

    /**
     * @return list<array{0: string, 1: int, 2: int, 3: list<string>}>
     */
    private static function countriesByCodeLength(): array
    {
        $rows = self::COUNTRIES;
        usort($rows, fn (array $a, array $b): int => strlen($b[0]) <=> strlen($a[0]));

        return $rows;
    }

    private static function digits(?string $value): ?string
    {
        $ascii = self::asciiDigits($value);
        $digits = preg_replace('/\D+/', '', $ascii) ?? '';

        return $digits === '' ? null : $digits;
    }

    private static function compact(?string $value): string
    {
        return preg_replace('/[^\d+]/', '', self::asciiDigits($value)) ?? '';
    }

    private static function asciiDigits(?string $value): string
    {
        return strtr((string) $value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }

    private static function normalizeWords(?string $value): string
    {
        $text = mb_strtolower(trim(self::asciiDigits($value)));
        $text = str_replace(['أ', 'إ', 'آ', 'ى', 'ة'], ['ا', 'ا', 'ا', 'ي', 'ه'], $text);
        $text = trim((string) preg_replace('/[^\p{L}\p{N}\s+]+/u', ' ', $text));

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
