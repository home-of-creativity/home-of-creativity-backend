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

    /**
     * @return array{slug: string, title_ar: string, title_en: string, sections: list<array<string, string>>}
     */
    public static function scaffold(string $slug): array
    {
        abort_unless(in_array($slug, self::SLUGS, true), 404);

        return [
            'slug' => $slug,
            'title_ar' => $slug === 'terms' ? 'شروط الاستخدام' : 'سياسة الخصوصية',
            'title_en' => $slug === 'terms' ? 'Terms of Use' : 'Privacy Policy',
            'sections' => [[
                'id' => 'content',
                'heading_ar' => '',
                'heading_en' => '',
                'html_ar' => '',
                'html_en' => '',
            ]],
        ];
    }

    public function hasPublishedContent(): bool
    {
        foreach ($this->sections ?? [] as $section) {
            if (! is_array($section)) {
                continue;
            }

            foreach (['html_ar', 'html_en'] as $key) {
                $html = trim(strip_tags((string) ($section[$key] ?? '')));
                if ($html !== '') {
                    return true;
                }
            }
        }

        return false;
    }
}
