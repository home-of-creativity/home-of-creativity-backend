<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PricingCategory extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'name_en',
        'name_ar',
        'lead_en',
        'lead_ar',
        'sort_order',
        'is_published',
        'requires_full_payment',
        'allows_renewal',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_published' => 'boolean',
            'requires_full_payment' => 'boolean',
            'allows_renewal' => 'boolean',
        ];
    }

    public function subcategories(): HasMany
    {
        return $this->hasMany(PricingSubcategory::class, 'category_id');
    }

    protected $attributes = [
        'requires_full_payment' => false,
        'allows_renewal' => false,
        'is_published' => true,
    ];
}
