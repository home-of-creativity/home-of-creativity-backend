<?php

namespace App\Support;

class PricingAmounts
{
    /**
     * @return array{monthly: int, quarterly: int, semiannual: int, yearly: int}
     */
    public static function fromMonthly(int $monthly): array
    {
        return [
            'monthly' => $monthly,
            'quarterly' => (int) round($monthly * 3 * 0.95),
            'semiannual' => (int) round($monthly * 6 * 0.9),
            'yearly' => (int) round($monthly * 12 * 0.8),
        ];
    }
}
