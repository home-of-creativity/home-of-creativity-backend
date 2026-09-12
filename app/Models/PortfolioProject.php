<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PortfolioProject extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'title_en',
        'title_ar',
        'summary_en',
        'summary_ar',
        'website_url',
        'social_links',
        'image_path',
        'sort_order',
        'is_published',
        'featured',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'sort_order' => 'integer',
            'is_published' => 'boolean',
            'featured' => 'boolean',
            'social_links' => 'array',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PortfolioCategory::class, 'category_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(PortfolioProjectImage::class)->orderBy('sort_order')->orderBy('id');
    }
}
