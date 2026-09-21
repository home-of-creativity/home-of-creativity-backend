<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PricingCategory;
use App\Models\PricingPackage;
use App\Models\PricingSubcategory;
use App\Services\TelegramNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppClientBotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.token' => 'wa-token',
            'services.whatsapp.phone_number_id' => '555',
            'services.whatsapp.verify_token' => 'verify-me',
            'services.whatsapp.app_secret' => 'app-secret',
            'services.whatsapp.graph_base' => 'https://graph.facebook.com/v21.0',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
        ]);
    }

    public function test_verify_challenge_requires_matching_token(): void
    {
        $this->get('/api/bot/whatsapp/webhook?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'verify-me',
            'hub.challenge' => 'challenge-token',
        ]))->assertOk()->assertSee('challenge-token');

        $this->get('/api/bot/whatsapp/webhook?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'wrong',
            'hub.challenge' => 'challenge-token',
        ]))->assertForbidden();
    }

    public function test_rejects_unsigned_post(): void
    {
        $this->postJson('/api/bot/whatsapp/webhook', ['object' => 'whatsapp_business_account'])
            ->assertUnauthorized();
    }

    public function test_inbound_hello_links_whatsapp_client_and_asks_for_company(): void
    {
        $this->signedPost($this->textPayload('963911111111', 'Nour', 'مرحبا', 'wamid.1'))
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $client = Client::query()->where('telegram_user_id', 'wa:963911111111')->firstOrFail();
        $this->assertSame('Nour', $client->name);
        $this->assertSame('+963911111111', $client->phone);
        $this->assertFalse($client->profileComplete());
        $this->assertSame('https://wa.me/963911111111', $client->telegramPrivateUrl());
        $this->assertSame('whatsapp', $client->requestSource()->value);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/555/messages')
            && data_get($request->data(), 'to') === '963911111111');
    }

    public function test_company_reply_completes_profile(): void
    {
        $this->signedPost($this->textPayload('963911111111', 'Nour', 'مرحبا', 'wamid.1'))->assertOk();
        $this->signedPost($this->textPayload('963911111111', 'Nour', 'شركة نون', 'wamid.2'))->assertOk();

        $client = Client::query()->where('telegram_user_id', 'wa:963911111111')->firstOrFail();
        $this->assertTrue($client->profileComplete());
        $this->assertSame('شركة نون', $client->company_name);
        $this->assertTrue($client->isWhatsApp());
    }

    public function test_new_request_sends_catalog_choices(): void
    {
        $this->seedPackage();
        $this->signedPost($this->textPayload('963911111111', 'Nour', 'مرحبا', 'wamid.1'))->assertOk();
        $this->signedPost($this->textPayload('963911111111', 'Nour', 'شركة نون', 'wamid.2'))->assertOk();
        $this->signedPost($this->textPayload('963911111111', 'Nour', 'طلب جديد', 'wamid.3'))->assertOk();

        Http::assertSent(function (Request $request): bool {
            $text = (string) data_get($request->data(), 'interactive.body.text');
            $titles = collect(data_get($request->data(), 'interactive.action.buttons', []))
                ->pluck('reply.title')
                ->implode(' ');

            return str_contains($request->url(), '/555/messages')
                && (str_contains($text, 'اختر الفئة') || str_contains($titles, 'اشتراكات'));
        });
    }

    public function test_notifier_routes_wa_chats_to_graph(): void
    {
        app(TelegramNotifier::class)->send('wa:963911111111', 'hello');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'graph.facebook.com')
            && data_get($request->data(), 'type') === 'text'
            && data_get($request->data(), 'text.body') === 'hello');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signedPost(array $payload)
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($body);

        return $this->call('POST', '/api/bot/whatsapp/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
        ], $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function textPayload(string $from, string $name, string $body, string $wamid): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'contacts' => [[
                            'profile' => ['name' => $name],
                            'wa_id' => $from,
                        ]],
                        'messages' => [[
                            'from' => $from,
                            'id' => $wamid,
                            'timestamp' => '1710000000',
                            'type' => 'text',
                            'text' => ['body' => $body],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function seedPackage(): PricingPackage
    {
        $category = PricingCategory::query()->create([
            'slug' => 'retainers-wa',
            'name_en' => 'Retainers',
            'name_ar' => 'اشتراكات',
            'sort_order' => 1,
            'is_published' => true,
            'requires_full_payment' => false,
            'allows_renewal' => true,
        ]);
        $sub = PricingSubcategory::query()->create([
            'category_id' => $category->id,
            'slug' => 'monthly-wa',
            'name_en' => 'Monthly',
            'name_ar' => 'شهري',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        return PricingPackage::query()->create([
            'subcategory_id' => $sub->id,
            'slug' => 'startup-wa',
            'name_en' => 'Startup',
            'name_ar' => 'Startup',
            'subtitle_en' => 'Small',
            'subtitle_ar' => 'صغير',
            'prices' => ['monthly' => 399],
            'features' => [],
            'sort_order' => 1,
            'is_published' => true,
        ]);
    }
}
