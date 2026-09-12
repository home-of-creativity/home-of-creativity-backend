<?php

namespace Database\Seeders;

use App\Models\ContactChannel;
use Illuminate\Database\Seeder;

class ContactSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['kind' => 'mobile', 'region' => 'SYR', 'value' => '+963 968 862 822', 'digits' => '963968862822', 'sort_order' => 1],
            ['kind' => 'mobile', 'region' => 'KSA', 'value' => '+966 55 035 0295', 'digits' => '966550350295', 'sort_order' => 2],
            ['kind' => 'whatsapp', 'region' => 'SYR', 'value' => '+963 954 187 154', 'digits' => '963954187154', 'sort_order' => 1],
            ['kind' => 'whatsapp', 'region' => 'KSA', 'value' => '+966 55 035 0295', 'digits' => '966550350295', 'sort_order' => 2],
            ['kind' => 'social', 'platform' => 'instagram', 'value' => 'Instagram', 'value_ar' => 'إنستغرام', 'url' => 'https://www.instagram.com/homeofcreativity/', 'sort_order' => 1],
            ['kind' => 'social', 'platform' => 'facebook', 'value' => 'Facebook', 'value_ar' => 'فيسبوك', 'url' => 'https://www.facebook.com/homeofcreativity', 'sort_order' => 2],
            ['kind' => 'location', 'region' => 'SYR', 'value' => 'Damascus, Al Hamra', 'value_ar' => 'دمشق، الحمراء', 'sort_order' => 1],
            ['kind' => 'location', 'region' => 'KSA', 'value' => 'Riyadh, Al Murabaa', 'value_ar' => 'الرياض، المربّع', 'sort_order' => 2],
        ];

        foreach ($rows as $row) {
            ContactChannel::query()->updateOrCreate(
                [
                    'kind' => $row['kind'],
                    'region' => $row['region'] ?? null,
                    'platform' => $row['platform'] ?? null,
                    'digits' => $row['digits'] ?? null,
                    'url' => $row['url'] ?? null,
                ],
                [
                    'value' => $row['value'],
                    'value_ar' => $row['value_ar'] ?? null,
                    'sort_order' => $row['sort_order'],
                    'is_published' => true,
                ],
            );
        }
    }
}
