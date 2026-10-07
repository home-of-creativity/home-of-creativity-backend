<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Support\ClientChannelGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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
}
