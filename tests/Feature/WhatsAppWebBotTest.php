<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\PricingCategory;
use App\Models\PricingPackage;
use App\Models\PricingSubcategory;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Support\ClientChannelGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppWebBotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.transport' => 'web',
            'services.whatsapp.enabled' => false,
            'services.whatsapp.web_url' => 'http://wa-web.test',
            'services.whatsapp.web_secret' => 'web-secret',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'http://wa-web.test/*' => Http::response(['id' => 'wa-web-1'], 200),
        ]);
    }

    public function test_web_transport_unlocks_without_meta(): void
    {
        $this->assertFalse(ClientChannelGate::whatsappLocked());
        $this->assertTrue(ClientChannelGate::whatsappEnabled());
        $this->assertSame('web', ClientChannelGate::payload()['whatsapp_transport']);
    }

    public function test_web_inbound_requires_the_bridge_secret(): void
    {
        $this->postJson('/api/bot/whatsapp/web', [
            'phone' => '963911111111',
            'message_id' => 'web-1',
            'text' => 'مرحبا',
        ])->assertUnauthorized();
    }

    public function test_web_inbound_links_the_client_and_replies_through_the_bridge(): void
    {
        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => '963911111111',
                'profile_name' => 'Nour',
                'message_id' => 'web-1',
                'text' => 'مرحبا',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $client = Client::query()->where('telegram_user_id', 'wa:963911111111')->firstOrFail();
        $this->assertSame('Nour', $client->name);
        $this->assertSame('+963911111111', $client->phone);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://wa-web.test/send'
            && $request->hasHeader('X-Webhook-Secret', 'web-secret')
            && data_get($request->data(), 'to') === '963911111111');
    }

    public function test_a_free_question_is_answered_from_the_published_brief(): void
    {
        config(['services.gemini.e2e_stub' => true]);
        Cache::put('site-guide-v3', 'Home of Creativity brief', now()->addMinute());
        Client::query()->create([
            'name' => 'Nour',
            'phone' => '+963922222222',
            'company_name' => 'Nour Co',
            'telegram_user_id' => 'wa:963922222222',
            'locale' => 'ar',
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => '963922222222',
                'message_id' => 'web-ask',
                'text' => 'وين مكتبكم؟',
            ])
            ->assertOk();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://wa-web.test/send'
            && str_contains((string) data_get($request->data(), 'text'), 'hoc.agency')
            && collect(data_get($request->data(), 'buttons', []))->contains('title', 'استفسار'));
    }

    public function test_the_client_opens_a_request_and_edits_it_before_a_quotation(): void
    {
        $client = Client::query()->create([
            'name' => 'Nour',
            'phone' => '+963933333333',
            'company_name' => 'Nour Co',
            'telegram_user_id' => 'wa:963933333333',
            'locale' => 'ar',
        ]);
        $serviceRequest = ServiceRequest::factory()->create([
            'client_id' => $client->id,
            'title' => 'الشعار القديم',
            'description' => 'وصف قديم للطلب يكفي هنا.',
            'status' => RequestStatus::Submitted,
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => '963933333333',
                'message_id' => 'web-list',
                'text' => 'طلباتي',
            ])
            ->assertOk();

        Http::assertSent(fn (Request $request): bool => collect(data_get($request->data(), 'buttons', []))
            ->contains('id', 'open:'.$serviceRequest->number));

        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => '963933333333',
                'message_id' => 'web-edit',
                'button_id' => 'edit:'.$serviceRequest->number,
            ])
            ->assertOk();

        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => '963933333333',
                'message_id' => 'web-title',
                'text' => 'الشعار الجديد',
            ])
            ->assertOk();

        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => '963933333333',
                'message_id' => 'web-body',
                'text' => 'وصف جديد يوضح التعديل المطلوب على الشعار.',
            ])
            ->assertOk();

        $serviceRequest->refresh();
        $this->assertSame('الشعار الجديد', $serviceRequest->title);
        $this->assertSame('وصف جديد يوضح التعديل المطلوب على الشعار.', $serviceRequest->description);
    }

    public function test_a_pending_quotation_stays_on_approve_or_reject(): void
    {
        $client = Client::query()->create([
            'name' => 'Nour',
            'phone' => '+963955555555',
            'company_name' => 'Nour Co',
            'telegram_user_id' => 'wa:963955555555',
            'locale' => 'ar',
        ]);
        $serviceRequest = ServiceRequest::factory()->create([
            'client_id' => $client->id,
            'status' => RequestStatus::QuotationSent,
            'quotation_amount' => 100,
        ]);
        Quotation::query()->create([
            'request_id' => $serviceRequest->id,
            'version' => 1,
            'amount' => 100,
            'sent_at' => now(),
        ]);
        Cache::put('hoc:wa-session:963955555555', [
            'step' => 'quote',
            'quote_ref' => $serviceRequest->number,
        ], now()->addHour());

        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => '963955555555',
                'message_id' => 'web-approve',
                'text' => '1',
            ])
            ->assertOk();

        $this->assertSame(RequestStatus::AwaitingPayment, $serviceRequest->fresh()->status);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://wa-web.test/send'
            && str_contains((string) data_get($request->data(), 'text'), 'وصل')
            && ! str_contains((string) data_get($request->data(), 'text'), 'اختر الفئة'));
    }

    public function test_gemini_suggests_a_package_and_offers_to_open_it(): void
    {
        config([
            'services.gemini.e2e_stub' => false,
            'services.gemini.api_key' => 'test-key',
            'services.gemini.vertex_project' => '',
        ]);
        $category = PricingCategory::query()->create([
            'slug' => 'strategic',
            'name_en' => 'Strategic',
            'name_ar' => 'حلول استراتيجية',
            'is_published' => true,
        ]);
        $subcategory = PricingSubcategory::query()->create([
            'category_id' => $category->id,
            'slug' => 'plans',
            'name_en' => 'Plans',
            'name_ar' => 'باقات',
            'is_published' => true,
        ]);
        $package = PricingPackage::query()->create([
            'subcategory_id' => $subcategory->id,
            'slug' => 'startup-build',
            'name_en' => 'Startup Build',
            'name_ar' => 'Startup Build',
            'subtitle_ar' => 'منشآت صغيرة',
            'subtitle_en' => 'Small business',
            'prices' => ['monthly' => 399, 'quarterly' => 1137],
            'is_published' => true,
        ]);
        Client::query()->create([
            'name' => 'Nour',
            'phone' => '+963966666666',
            'company_name' => 'Nour Co',
            'telegram_user_id' => 'wa:963966666666',
            'locale' => 'ar',
        ]);
        Http::fake([
            'http://wa-web.test/*' => Http::response(['id' => 'wa-web-1'], 200),
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => '{"id":'.$package->id.',"period":"quarterly","answer":"الباقة المناسبة لمحل واحد هي Startup Build."}',
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => '963966666666',
                'message_id' => 'web-shop',
                'text' => 'لدي محل موالح اريد افضل باقة للاشتراك',
            ])
            ->assertOk();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://wa-web.test/send'
            && str_contains((string) data_get($request->data(), 'text'), 'Startup Build')
            && collect(data_get($request->data(), 'buttons', []))->contains('title', 'أنشئ الطلب'));
    }

    public function test_the_client_can_replace_the_company_name(): void
    {
        $client = Client::query()->create([
            'name' => 'Nour',
            'phone' => '+963977777777',
            'company_name' => 'المحل القديم',
            'telegram_user_id' => 'wa:963977777777',
            'locale' => 'ar',
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => '963977777777',
                'message_id' => 'web-profile',
                'button_id' => 'menu:profile',
            ])
            ->assertOk();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://wa-web.test/send'
            && str_contains((string) data_get($request->data(), 'text'), 'المحل القديم')
            && collect(data_get($request->data(), 'buttons', []))->contains('title', 'تعديل الشركة'));

        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => '963977777777',
                'message_id' => 'web-company-field',
                'button_id' => 'prof:company_name',
            ])
            ->assertOk();

        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => '963977777777',
                'message_id' => 'web-company-value',
                'text' => 'محل الموالح',
            ])
            ->assertOk();

        $this->assertSame('محل الموالح', $client->fresh()->company_name);
    }

    public function test_a_sentence_about_the_company_opens_that_field(): void
    {
        config(['services.gemini.e2e_stub' => true]);
        Client::query()->create([
            'name' => 'Nour',
            'phone' => '+963988888888',
            'company_name' => 'المحل القديم',
            'telegram_user_id' => 'wa:963988888888',
            'locale' => 'ar',
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => '963988888888',
                'message_id' => 'web-company-sentence',
                'text' => 'بدي غير اسم الشركة لأنو غلط',
            ])
            ->assertOk();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://wa-web.test/send'
            && str_contains((string) data_get($request->data(), 'text'), 'اسم الشركة الجديد'));
    }

    public function test_a_sentence_is_routed_by_meaning_for_orders_and_for_a_quotation(): void
    {
        config([
            'services.gemini.e2e_stub' => false,
            'services.gemini.api_key' => 'test-key',
            'services.gemini.vertex_project' => '',
        ]);
        $client = Client::query()->create([
            'name' => 'Nour',
            'phone' => '+963999999999',
            'company_name' => 'Nour Co',
            'telegram_user_id' => 'wa:963999999999',
            'locale' => 'ar',
        ]);
        $serviceRequest = ServiceRequest::factory()->create([
            'client_id' => $client->id,
            'status' => RequestStatus::QuotationSent,
            'quotation_amount' => 100,
        ]);
        Quotation::query()->create([
            'request_id' => $serviceRequest->id,
            'version' => 1,
            'amount' => 100,
            'sent_at' => now(),
        ]);
        Cache::put('hoc:wa-session:963999999999', [
            'step' => 'quote',
            'quote_ref' => $serviceRequest->number,
        ], now()->addHour());
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            if (str_contains($request->url(), 'wa-web.test')) {
                return Http::response(['id' => 'wa-web-1'], 200);
            }
            $prompt = (string) data_get($request->data(), 'contents.0.parts.0.text');
            $intent = str_contains($prompt, 'شو صار بشغلي') ? 'requests' : 'approve';

            return Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"intent":"'.$intent.'"}']]],
                ]],
            ], 200);
        });

        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => '963999999999',
                'message_id' => 'web-meaning-approve',
                'text' => 'السعر مناسب خلينا نبلش الشغل',
            ])
            ->assertOk();

        $this->assertSame(RequestStatus::AwaitingPayment, $serviceRequest->fresh()->status);

        Cache::put('hoc:wa-session:963999999998', ['step' => 'idle'], now()->addHour());
        Client::query()->create([
            'name' => 'Lina',
            'phone' => '+963999999998',
            'company_name' => 'Lina Co',
            'telegram_user_id' => 'wa:963999999998',
            'locale' => 'ar',
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => '963999999998',
                'message_id' => 'web-meaning-orders',
                'text' => 'شو صار بشغلي عندكم',
            ])
            ->assertOk();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://wa-web.test/send'
            && str_contains((string) data_get($request->data(), 'text'), 'لا توجد طلبات'));
    }

    public function test_the_chat_follows_the_language_of_the_latest_message(): void
    {
        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => '963922222222',
                'profile_name' => 'Nour',
                'message_id' => 'web-lang-en',
                'text' => 'Hello',
            ])
            ->assertOk();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://wa-web.test/send'
            && str_contains((string) data_get($request->data(), 'text'), 'What is the company name?'));

        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => '963922222222',
                'message_id' => 'web-lang-company',
                'text' => 'Acme Studio',
            ])
            ->assertOk();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://wa-web.test/send'
            && str_contains((string) data_get($request->data(), 'text'), 'Your details are saved.'));

        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => '963922222222',
                'message_id' => 'web-lang-ar',
                'text' => 'مرحبا',
            ])
            ->assertOk();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://wa-web.test/send'
            && str_contains((string) data_get($request->data(), 'text'), 'أهلاً'));
        $this->assertSame('ar', Client::query()->where('telegram_user_id', 'wa:963922222222')->firstOrFail()->locale);
    }
}
