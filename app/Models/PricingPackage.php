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
        ];
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(PricingSubcategory::class, 'subcategory_id');
    }
}
