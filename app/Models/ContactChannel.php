<?php

namespace App\Models;

use Database\Factories\ContactChannelFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactChannel extends Model
{
    /** @use HasFactory<ContactChannelFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'kind',
        'region',
        'platform',
        'value',
        'value_ar',
        'digits',
        'url',
        'sort_order',
        'is_published',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sort_order' => 0,
        'is_published' => true,
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

    /**
     * @param  Builder<ContactChannel>  $query
     * @return Builder<ContactChannel>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }
}
