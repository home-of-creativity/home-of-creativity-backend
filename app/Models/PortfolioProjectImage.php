<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioProjectImage extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'portfolio_project_id',
        'image_path',
        'alt_en',
        'alt_ar',
        'sort_order',
        'featured',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'portfolio_project_id' => 'integer',
            'sort_order' => 'integer',
            'featured' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(PortfolioProject::class, 'portfolio_project_id');
    }
}
