<?php

namespace Database\Seeders;

use App\Models\PortfolioCategory;
use App\Models\PortfolioProject;
use App\Models\PortfolioProjectImage;
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

        $drive = require database_path('data/portfolio-drive-images.php');
        $files = $drive['files'];

        foreach ($drive['projects'] as $projectData) {
            $category = PortfolioCategory::query()->where('slug', $projectData['category_slug'])->first();
            if (! $category) {
                continue;
            }

            $cover = collect($projectData['gallery'])->firstWhere('featured', true)
                ?? $projectData['gallery'][0];
            $coverUrl = $files[$cover['file']]['url'] ?? null;

            $project = PortfolioProject::query()->updateOrCreate(
                [
                    'category_id' => $category->id,
                    'title_en' => $projectData['title_en'],
                ],
                [
                    'title_ar' => $projectData['title_ar'],
                    'summary_en' => $projectData['summary_en'],
                    'summary_ar' => $projectData['summary_ar'],
                    'image_path' => $coverUrl,
                    'sort_order' => $projectData['sort_order'],
                    'is_published' => true,
                    'featured' => $projectData['featured'],
                ],
            );

            $project->images()->delete();

            foreach ($projectData['gallery'] as $index => $imageData) {
                $url = $files[$imageData['file']]['url'] ?? null;
                if (! $url) {
                    continue;
                }

                PortfolioProjectImage::query()->create([
                    'portfolio_project_id' => $project->id,
                    'image_path' => $url,
                    'alt_en' => $imageData['alt_en'],
                    'alt_ar' => $imageData['alt_ar'],
                    'sort_order' => $index + 1,
                    'featured' => $imageData['featured'],
                ]);
            }
        }
    }
}
