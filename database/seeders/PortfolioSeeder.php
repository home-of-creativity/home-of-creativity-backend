<?php

namespace Database\Seeders;

use App\Models\PortfolioCategory;
use App\Models\ShowcaseClient;
use Illuminate\Database\Seeder;

class PortfolioSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['slug' => 'events', 'name_en' => 'Events', 'name_ar' => 'الفعاليات', 'sort_order' => 1],
            ['slug' => 'identity', 'name_en' => 'Identity', 'name_ar' => 'الهوية', 'sort_order' => 2],
            ['slug' => 'media', 'name_en' => 'Media', 'name_ar' => 'المحتوى', 'sort_order' => 3],
            ['slug' => 'promo', 'name_en' => 'Outdoor', 'name_ar' => 'الإعلان', 'sort_order' => 4],
            ['slug' => 'digital', 'name_en' => 'Web', 'name_ar' => 'المواقع', 'sort_order' => 5],
            ['slug' => 'finance', 'name_en' => 'Finance', 'name_ar' => 'المالي', 'sort_order' => 6],
        ];

        foreach ($categories as $category) {
            PortfolioCategory::query()->updateOrCreate(
                ['slug' => $category['slug']],
                $category + ['is_published' => true],
            );
        }

        $clients = [
            'IZORA',
            'Faiz Wahba',
            'Enginety',
            'Smart Vision',
            'Future Line',
            'Riva Boutique',
        ];

        foreach ($clients as $index => $name) {
            ShowcaseClient::query()->updateOrCreate(
                ['name' => $name],
                [
                    'sort_order' => $index + 1,
                    'is_published' => true,
                ],
            );
        }

    }
}
