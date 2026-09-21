<?php

namespace App\Models;

use Database\Factories\LegalPageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LegalPage extends Model
{
    /** @use HasFactory<LegalPageFactory> */
    use HasFactory;

    public const SLUGS = ['privacy', 'terms'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'title_ar',
        'title_en',
        'sections',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sections' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
