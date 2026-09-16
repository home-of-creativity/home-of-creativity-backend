<?php

namespace App\Support;

use Carbon\CarbonInterface;
use InvalidArgumentException;

class BillingPeriod
{
    /** @var list<string> */
    public const SUBSCRIPTION_KEYS = ['monthly', 'quarterly', 'semiannual', 'yearly'];

    public static function labelAr(string $period): string
    {
        return match ($period) {
            'monthly' => 'شهري',
            'quarterly' => 'ربع سنوي',
            'semiannual' => 'نصف سنوي',
            'yearly' => 'سنوي',
            'one_time' => 'دفعة واحدة',
            default => $period,
        };
    }

    public static function months(string $period): int
    {
        return match ($period) {
            'monthly' => 1,
            'quarterly' => 3,
            'semiannual' => 6,
            'yearly' => 12,
            'one_time' => 0,
            default => throw new InvalidArgumentException("Unknown billing period [{$period}]."),
        };
    }

    public static function addPeriod(CarbonInterface $from, string $period): CarbonInterface
    {
        $months = self::months($period);
        if ($months <= 0) {
            return $from->copy()->addMonth();
        }

        return $from->copy()->addMonthsNoOverflow($months);
    }

    public static function isSubscription(string $period): bool
    {
        return in_array($period, self::SUBSCRIPTION_KEYS, true);
    }

    /**
     * @param  array<string, mixed>|null  $prices
     * @return list<string>
     */
    public static function availableFromPrices(?array $prices): array
    {
        if (! is_array($prices) || $prices === []) {
            return [];
        }

        $keys = [];
        foreach (self::SUBSCRIPTION_KEYS as $key) {
            if (isset($prices[$key]) && is_numeric($prices[$key]) && (float) $prices[$key] > 0) {
                $keys[] = $key;
            }
        }

        if (isset($prices['one_time']) && is_numeric($prices['one_time']) && (float) $prices['one_time'] > 0) {
            $keys[] = 'one_time';
        }

        return $keys;
    }
}
