<?php

namespace App\Support;

use App\Models\PricingCategory;
use App\Models\PricingPackage;

class PaymentPlanResolver
{
    /**
     * @return array{requires_full_payment: bool, allows_renewal: bool, payment_plan: string}
     */
    public static function forPackage(?PricingPackage $package): array
    {
        $category = $package?->subcategory?->category;
        $allowsRenewal = (bool) ($category?->allows_renewal ?? false);
        $requiresFull = self::requiresFullPayment($package, $category);

        return [
            'requires_full_payment' => $requiresFull,
            'allows_renewal' => $allowsRenewal,
            'payment_plan' => $requiresFull ? 'full' : 'partial',
        ];
    }

    public static function requiresFullPayment(?PricingPackage $package, ?PricingCategory $category = null): bool
    {
        $category ??= $package?->subcategory?->category;

        if ($package !== null && $package->allows_partial_payment === false) {
            return true;
        }

        if ($package !== null && $package->allows_partial_payment === true) {
            return false;
        }

        if ($package !== null && self::hasReach($package)) {
            return true;
        }

        return (bool) ($category?->requires_full_payment ?? false);
    }

    public static function hasReach(PricingPackage $package): bool
    {
        $reach = $package->reach;

        return is_array($reach) && $reach !== [];
    }
}
