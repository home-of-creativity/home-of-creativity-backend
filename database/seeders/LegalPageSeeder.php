<?php

namespace Database\Seeders;

use App\Models\LegalPage;
use App\Support\LegalDefaults;
use Illuminate\Database\Seeder;

class LegalPageSeeder extends Seeder
{
    public function run(): void
    {
        foreach (LegalDefaults::pages() as $page) {
            LegalPage::query()->updateOrCreate(
                ['slug' => $page['slug']],
                [
                    'title_ar' => $page['title_ar'],
                    'title_en' => $page['title_en'],
                    'sections' => $page['sections'],
                ],
            );
        }
    }
}
