<?php

namespace Tests\Feature;

use App\Models\PricingCategory;
use App\Models\PricingPackage;
use App\Models\PricingSubcategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pricing_returns_published_tree(): void
    {
        $category = PricingCategory::query()->create([
            'slug' => 'strategic',
            'name_en' => 'Strategic solutions',
            'name_ar' => 'باقات الحلول الاسترategية',
            'lead_en' => 'Lead EN',
            'lead_ar' => 'Lead AR',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $subcategory = PricingSubcategory::query()->create([
            'category_id' => $category->id,
            'slug' => 'retainers',
            'name_en' => 'Retainers',
            'name_ar' => 'اشتراكات',
            'one_time' => false,
            'sort_order' => 1,
            'is_published' => true,
        ]);

        PricingPackage::query()->create([
            'subcategory_id' => $subcategory->id,
            'slug' => 'startup',
            'name_en' => 'Startup Build',
            'name_ar' => 'Startup Build',
            'subtitle_en' => 'Small business',
            'subtitle_ar' => 'منشآت صغيرة',
            'prices' => ['monthly' => 399, 'quarterly' => 1137, 'semiannual' => 2155, 'yearly' => 3830],
            'features' => [['en' => '7 posts', 'ar' => '7 بوست']],
            'sort_order' => 1,
            'is_published' => true,
        ]);

        $response = $this->getJson('/api/pricing');

        $response->assertOk()
            ->assertJsonPath('data.0.slug', 'strategic')
            ->assertJsonPath('data.0.subcategories.0.slug', 'retainers')
            ->assertJsonPath('data.0.subcategories.0.packages.0.slug', 'startup');
    }

    public function test_admin_can_create_pricing_category(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/pricing/categories', [
            'slug' => 'production',
            'name_en' => 'Flexible production',
            'name_ar' => 'إنتاج مرن',
            'lead_en' => 'Lead EN',
            'lead_ar' => 'Lead AR',
            'is_published' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'production');

        $this->assertDatabaseHas('pricing_categories', ['slug' => 'production']);
    }
}
