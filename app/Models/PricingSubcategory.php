<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PricingSubcategory extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'slug',
        'name_en',
        'name_ar',
        'lead_en',
        'lead_ar',
        'one_time',
        'lead_in_box',
        'lead_note_en',
        'lead_note_ar',
        'sort_order',
        'is_published',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'one_time' => 'boolean',
            'lead_in_box' => 'boolean',
            'sort_order' => 'integer',
            'is_published' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PricingCategory::class, 'category_id');
    }

    public function packages(): HasMany
    {
        return $this->hasMany(PricingPackage::class, 'subcategory_id');
    }
}
