<?php

namespace App\Support;

/**
 * Arabic names for Odoo payment terms. Odoo is asked in Arabic first; a term that has no
 * Arabic translation there (created in English) is read here from its usual wording.
 */
final class PaymentTermLabel
{
    private const EXACT = [
        'immediate payment' => 'الدفع الفوري',
        'immediate' => 'الدفع الفوري',
        'end of following month' => 'نهاية الشهر التالي',
        'end of month' => 'نهاية الشهر',
        'end of the following month' => 'نهاية الشهر التالي',
        'cash on delivery' => 'الدفع عند الاستلام',
        'payment on delivery' => 'الدفع عند الاستلام',
        'advance payment' => 'دفعة مقدّمة',
        '100% advance' => 'دفع كامل مقدّماً',
        '50% advance' => '50% مقدّماً',
    ];

    public static function arabic(string $name): string
    {
        $name = trim($name);
        if ($name === '' || preg_match('/\p{Arabic}/u', $name) === 1) {
            return $name;
        }
        $key = mb_strtolower(preg_replace('/\s+/', ' ', $name) ?? $name);
        if (isset(self::EXACT[$key])) {
            return self::EXACT[$key];
        }

        // «30 Days», «45 days»
        if (preg_match('/^(\d+)\s*days?$/i', $name, $match) === 1) {
            return 'خلال '.self::days((int) $match[1]);
        }
        // «30% Now, Balance 60 Days»
        if (preg_match('/^(\d+)\s*%\s*now,?\s*balance\s*(\d+)\s*days?$/i', $name, $match) === 1) {
            return "{$match[1]}% الآن، والباقي خلال ".self::days((int) $match[2]);
        }
        // «2/7 Net 30»: 2% off within 7 days, the full amount within 30
        if (preg_match('/^(\d+(?:\.\d+)?)\/(\d+)\s*net\s*(\d+)$/i', $name, $match) === 1) {
            return "خصم {$match[1]}% عند الدفع خلال ".self::days((int) $match[2]).'، والصافي خلال '.self::days((int) $match[3]);
        }
        // «Net 30»
        if (preg_match('/^net\s*(\d+)$/i', $name, $match) === 1) {
            return 'الصافي خلال '.self::days((int) $match[1]);
        }
        // «End of Month + 15 Days», «15 Days after End of Month»
        if (preg_match('/^end of (?:the )?month\s*\+\s*(\d+)\s*days?$/i', $name, $match) === 1
            || preg_match('/^(\d+)\s*days?\s*after end of (?:the )?month$/i', $name, $match) === 1) {
            return self::days((int) $match[1]).' بعد نهاية الشهر';
        }
        // «50% Now, 50% on delivery» and similar splits stay readable with the words translated.
        return strtr($name, [
            'Days' => 'يوماً',
            'days' => 'يوماً',
            'Day' => 'يوم',
            'Now' => 'الآن',
            'now' => 'الآن',
            'Balance' => 'الباقي',
            'balance' => 'الباقي',
            'on delivery' => 'عند الاستلام',
            'On Delivery' => 'عند الاستلام',
            'Advance' => 'مقدّماً',
            'advance' => 'مقدّماً',
        ]);
    }

    private static function days(int $count): string
    {
        return match (true) {
            $count === 1 => 'يوم واحد',
            $count === 2 => 'يومين',
            $count >= 3 && $count <= 10 => "{$count} أيام",
            default => "{$count} يوماً",
        };
    }
}
