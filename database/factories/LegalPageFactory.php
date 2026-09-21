<?php

namespace Database\Factories;

use App\Models\LegalPage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalPage>
 */
class LegalPageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => 'privacy',
            'title_ar' => 'سياسة الخصوصية',
            'title_en' => 'Privacy Policy',
            'sections' => [
                [
                    'id' => 'intro',
                    'heading_ar' => 'مقدمة',
                    'heading_en' => 'Introduction',
                    'html_ar' => '<p>نص تجريبي للخصوصية.</p>',
                    'html_en' => '<p>Sample privacy text.</p>',
                ],
            ],
        ];
    }

    public function terms(): static
    {
        return $this->state(fn () => [
            'slug' => 'terms',
            'title_ar' => 'شروط الاستخدام',
            'title_en' => 'Terms of Use',
            'sections' => [
                [
                    'id' => 'intro',
                    'heading_ar' => 'الاتفاق',
                    'heading_en' => 'The agreement',
                    'html_ar' => '<p>نص تجريبي للشروط.</p>',
                    'html_en' => '<p>Sample terms text.</p>',
                ],
            ],
        ]);
    }
}
