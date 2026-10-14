<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\PricingCategory;
use App\Models\PricingPackage;
use App\Models\PricingSubcategory;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Gemini writes a department's tasks from the package features; nothing is saved. */
class WorkPlanTaskSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private ServiceRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
        $category = PricingCategory::query()->create(['slug' => 'social', 'name_en' => 'Social', 'name_ar' => 'سوشال', 'is_published' => true]);
        $subcategory = PricingSubcategory::query()->create(['category_id' => $category->id, 'slug' => 'plans', 'name_en' => 'Plans', 'name_ar' => 'باقات', 'is_published' => true]);
        $package = PricingPackage::query()->create([
            'subcategory_id' => $subcategory->id,
            'slug' => 'growth',
            'name_en' => 'Growth',
            'name_ar' => 'نمو',
            'subtitle_en' => 'Mid',
            'subtitle_ar' => 'متوسطة',
            'prices' => ['monthly' => 899],
            'features' => [
                ['en' => '12 social posts', 'ar' => 'تصميم 12 بوست'],
                ['en' => 'Monthly content calendar', 'ar' => 'خطة محتوى شهرية'],
            ],
            'is_published' => true,
        ]);
        $this->request = ServiceRequest::factory()->create([
            'status' => RequestStatus::InProgress,
            'pricing_package_id' => $package->id,
            'title' => 'إدارة حسابات مطعم',
            'description' => 'مطعم شاورما في المزة',
        ]);
    }

    public function test_the_plan_lists_the_package_features(): void
    {
        $this->getJson("/api/admin/requests/{$this->request->id}/work-plan")
            ->assertOk()
            ->assertJsonPath('data.package_features', ['تصميم 12 بوست', 'خطة محتوى شهرية']);
    }

    public function test_gemini_tasks_are_returned_for_review(): void
    {
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.e2e_stub' => false, 'services.gemini.vertex_project' => null]);
        Http::fake([
            '*generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['tasks' => [
                ['title' => 'تصميم 12 بوست للمطعم', 'description' => 'مقاس 1080 مربع، بألوان الهوية'],
            ]])]]]]]], 200),
        ]);

        $this->postJson("/api/admin/requests/{$this->request->id}/work-plan/suggest-tasks", [
            'department' => 'design',
            'brief' => 'تصاميم الشهر',
            'tasks' => [['title' => 'بوستات', 'description' => '']],
        ])
            ->assertOk()
            ->assertJsonPath('data.tasks.0.title', 'تصميم 12 بوست للمطعم')
            ->assertJsonPath('data.tasks.0.description', 'مقاس 1080 مربع، بألوان الهوية');

        Http::assertSent(fn (Request $sent): bool => str_contains((string) json_encode($sent->data(), JSON_UNESCAPED_UNICODE), 'تصميم 12 بوست')
            && str_contains((string) json_encode($sent->data(), JSON_UNESCAPED_UNICODE), 'بوستات'));
        $this->assertNull(data_get($this->request->fresh()->work_plan, 'operations'));
    }

    public function test_without_gemini_each_feature_becomes_a_task(): void
    {
        config(['services.gemini.api_key' => '', 'services.gemini.e2e_stub' => false, 'services.gemini.vertex_project' => null]);

        $this->postJson("/api/admin/requests/{$this->request->id}/work-plan/suggest-tasks", ['department' => 'design'])
            ->assertOk()
            ->assertJsonPath('data.tasks.0.title', 'تصميم 12 بوست')
            ->assertJsonPath('data.tasks.1.title', 'خطة محتوى شهرية');
    }
}
