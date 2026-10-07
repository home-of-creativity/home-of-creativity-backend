<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\Client;
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
        Cache::put('site-guide-v2', 'Home of Creativity brief', now()->addMinute());
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
}
