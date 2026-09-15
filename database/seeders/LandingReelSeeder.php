<?php

namespace Database\Seeders;

use App\Models\LandingReel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class LandingReelSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            [
                'title_en' => 'Abu Shaker — Top List',
                'title_ar' => 'أبو شاكر — توب ليست',
                'file' => 'abu-shaker-top-list.mp4',
                'sort_order' => 1,
            ],
            [
                'title_en' => 'Visual identity — Donuts World',
                'title_ar' => 'الهوية البصرية — عالم الدونات',
                'file' => 'donuts-world-identity.mp4',
                'sort_order' => 2,
            ],
            [
                'title_en' => 'Home of Creativity — work overview',
                'title_ar' => 'لمحة عن أعمال بيت الإبداع',
                'file' => 'home-of-creativity-showreel.mp4',
                'sort_order' => 3,
            ],
        ];

        foreach ($items as $item) {
            $relative = 'reels/'.$item['file'];
            $full = storage_path('app/public/'.$relative);
            if (! File::isFile($full)) {
                continue;
            }

            LandingReel::query()->updateOrCreate(
                ['title_en' => $item['title_en']],
                [
                    'title_ar' => $item['title_ar'],
                    'video_path' => $relative,
                    'sort_order' => $item['sort_order'],
                    'is_published' => true,
                ],
            );
        }
    }
}
