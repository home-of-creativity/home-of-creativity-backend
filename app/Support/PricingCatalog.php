<?php

namespace App\Support;

use App\Models\PricingCategory;
use App\Models\PricingPackage;
use App\Models\PricingSubcategory;

class PricingCatalog
{
    public const PAGE_SIZE = 8;

    /**
     * @return list<array{id: int, name: string, has_subcategories: bool}>
     */
    public function categories(): array
    {
        return PricingCategory::query()
            ->where('is_published', true)
            ->orderBy('sort_order')
            ->orderBy('name_ar')
            ->withCount(['subcategories as published_subcategories_count' => fn ($q) => $q->where('is_published', true)])
            ->get()
            ->map(fn (PricingCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name_ar ?: $category->name_en,
                'has_subcategories' => $category->published_subcategories_count > 0,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, name: string, type: string}>
     */
    public function categoryChildren(int $categoryId): array
    {
        $category = PricingCategory::query()->where('is_published', true)->findOrFail($categoryId);

        $subs = $category->subcategories()
            ->where('is_published', true)
            ->orderBy('sort_order')
            ->orderBy('name_ar')
            ->get();

        if ($subs->isNotEmpty()) {
            return $subs->map(fn (PricingSubcategory $sub): array => [
                'id' => $sub->id,
                'name' => $sub->name_ar ?: $sub->name_en,
                'type' => 'subcategory',
            ])->values()->all();
        }

        $packages = PricingPackage::query()
            ->where('is_published', true)
            ->whereHas('subcategory', fn ($q) => $q->where('category_id', $category->id))
            ->orderBy('sort_order')
            ->orderBy('name_ar')
            ->get();

        return $packages->map(fn (PricingPackage $package): array => [
            'id' => $package->id,
            'name' => $package->name_ar ?: $package->name_en,
            'type' => 'package',
        ])->values()->all();
    }

    /**
     * @return list<array{id: int, name: string, type: string}>
     */
    public function subcategoryPackages(int $subcategoryId): array
    {
        $packages = PricingPackage::query()
            ->where('subcategory_id', $subcategoryId)
            ->where('is_published', true)
            ->orderBy('sort_order')
            ->orderBy('name_ar')
            ->get();

        return $packages->map(fn (PricingPackage $package): array => [
            'id' => $package->id,
            'name' => $package->name_ar ?: $package->name_en,
            'type' => 'package',
        ])->values()->all();
    }

    /**
     * @return array{id: int, name: string, periods: list<string>}
     */
    public function packagePeriods(int $packageId): array
    {
        $package = PricingPackage::query()->where('is_published', true)->findOrFail($packageId);
        $periods = BillingPeriod::availableFromPrices(is_array($package->prices) ? $package->prices : []);
        if ($periods === [] && $package->price_usd) {
            $periods = ['one_time'];
        }

        return [
            'id' => $package->id,
            'name' => $package->name_ar ?: $package->name_en,
            'periods' => $periods,
        ];
    }
}
