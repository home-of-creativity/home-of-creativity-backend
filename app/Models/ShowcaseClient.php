<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShowcaseClient extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'logo_path',
        'website_url',
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
}
