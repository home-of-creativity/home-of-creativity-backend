<?php

namespace Tests\Feature;

use App\Models\PricingCategory;
use App\Models\PricingPackage;
use App\Models\PricingSubcategory;
use App\Services\SiteGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteAskTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_question_is_answered_from_gemini(): void
    {
        config([
            'services.gemini.e2e_stub' => false,
            'services.gemini.api_key' => 'test-key',
            'services.gemini.vertex_project' => '',
            'services.site.guide_url' => 'https://hoc.agency/llms-full.txt',
        ]);
        Http::fake([
            'https://hoc.agency/llms-full.txt' => Http::response("Home of Creativity brief\n", 200),
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => '{"answer":"المكاتب المنشورة في دمشق والرياض."}',
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $this->postJson('/api/site/ask', [
            'question' => 'أين المكتب؟',
            'locale' => 'ar',
        ])->assertOk()->assertJsonPath('data.answer', 'المكاتب المنشورة في دمشق والرياض.');
    }

    public function test_an_empty_question_is_rejected(): void
    {
        $this->postJson('/api/site/ask', [
            'question' => '   ',
        ])->assertStatus(422);
    }

    public function test_the_guide_includes_each_package_price_and_features(): void
    {
        Cache::flush();
        config(['services.site.guide_url' => 'https://hoc.agency/llms-full.txt']);
        Http::fake([
            'https://hoc.agency/llms-full.txt' => Http::response('brief', 200),
        ]);

        $category = PricingCategory::query()->create([
            'slug' => 'strategic',
            'name_en' => 'Strategic',
            'name_ar' => 'حلول استراتيجية',
        ]);
        $subcategory = PricingSubcategory::query()->create([
            'category_id' => $category->id,
            'slug' => 'plans',
            'name_en' => 'Plans',
            'name_ar' => 'باقات',
        ]);
        PricingPackage::query()->create([
            'subcategory_id' => $subcategory->id,
            'slug' => 'startup-build',
            'name_en' => 'Startup Build',
            'name_ar' => 'Startup Build',
            'subtitle_en' => 'Small business',
            'subtitle_ar' => 'منشآت صغيرة',
            'prices' => ['monthly' => 399, 'quarterly' => 1137, 'semiannual' => 2155, 'yearly' => 3830],
            'features' => [
                ['en' => '7 graphic posts', 'ar' => '7 بوست غرافيك'],
            ],
        ]);

        $brief = app(SiteGuide::class)->brief();

        $this->assertStringContainsString('حلول استراتيجية', $brief);
        $this->assertStringContainsString('monthly $399', $brief);
        $this->assertStringContainsString('7 بوست غرافيك', $brief);
        $this->assertStringContainsString('منشآت صغيرة', $brief);
        $this->assertStringContainsString('Private information stays closed', $brief);
    }

    public function test_a_shop_recommendation_is_judged_from_practice_and_not_from_private_records(): void
    {
        config([
            'services.gemini.e2e_stub' => false,
            'services.gemini.api_key' => 'test-key',
            'services.gemini.vertex_project' => '',
            'services.site.guide_url' => 'https://hoc.agency/llms-full.txt',
        ]);
        Cache::flush();
        Http::fake([
            'https://hoc.agency/llms-full.txt' => Http::response('brief', 200),
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => '{"answer":"الباقة المناسبة لمحل واحد هي المنشآت الصغيرة."}',
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $this->postJson('/api/site/ask', [
            'question' => 'لدي محل موالح اريد افضل باقة عندكم من اجل الاشتراك فيها',
            'locale' => 'ar',
        ])->assertOk();

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            if (! str_contains($request->url(), 'generativelanguage.googleapis.com')) {
                return false;
            }
            $prompt = (string) data_get($request->data(), 'contents.0.parts.0.text');

            return str_contains($prompt, 'لدي محل موالح')
                && str_contains($prompt, 'recommend exactly one published package')
                && str_contains($prompt, 'Private information is closed')
                && str_contains($prompt, 'Odoo')
                && ! str_contains($prompt, 'crm.lead');
        });
    }

    public function test_the_answer_is_unavailable_without_a_gemini_key(): void
    {
        config([
            'services.gemini.e2e_stub' => false,
            'services.gemini.api_key' => '',
            'services.gemini.vertex_project' => '',
            'services.site.guide_url' => 'https://hoc.agency/llms-full.txt',
        ]);
        Http::fake([
            'https://hoc.agency/llms-full.txt' => Http::response('brief', 200),
        ]);

        $this->postJson('/api/site/ask', [
            'question' => 'ما الخدمات؟',
        ])->assertStatus(503)->assertJsonPath('message', 'unavailable');
    }
}
