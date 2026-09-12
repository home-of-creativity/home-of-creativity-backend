<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PortfolioCategory extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'name_en',
        'name_ar',
        'sort_order',
        'is_published',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_published' => 'boolean',
        ];
    }

    public function projects(): HasMany
    {
        return $this->hasMany(PortfolioProject::class, 'category_id');
    }
}
