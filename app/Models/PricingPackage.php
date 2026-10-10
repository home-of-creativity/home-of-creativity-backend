<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingPackage extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'subcategory_id',
        'slug',
        'name_en',
        'name_ar',
        'subtitle_en',
        'subtitle_ar',
        'price_usd',
        'prices',
        'features',
        'reach',
        'featured',
        'badge_en',
        'badge_ar',
        'sort_order',
        'is_published',
        'allows_partial_payment',
        'work_lines',
        'photography_sessions',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subcategory_id' => 'integer',
            'price_usd' => 'integer',
            'prices' => 'array',
            'features' => 'array',
            'reach' => 'array',
            'featured' => 'boolean',
            'sort_order' => 'integer',
            'is_published' => 'boolean',
            'allows_partial_payment' => 'boolean',
            'work_lines' => 'array',
            'photography_sessions' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Open paid requests that never had a count take this one once. A count a
        // person already wrote on the request is not replaced.
        static::saved(function (self $package): void {
            if ($package->wasChanged('photography_sessions') || $package->wasRecentlyCreated) {
                app(\App\Actions\PhotographySessions::class)->copyPackageCount($package);
            }
        });
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(PricingSubcategory::class, 'subcategory_id');
    }
}
