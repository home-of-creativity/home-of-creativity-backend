<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppBridgeRepairTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.n8n.webhook_secret' => 'n8n-secret',
            'services.whatsapp.web_url' => 'http://wa-web.test',
            'services.whatsapp.web_secret' => 'web-secret',
            'services.whatsapp.web_container' => '',
            'services.telegram.dev_bot_token' => 'dev-token',
            'services.telegram.dev_chat_id' => '42',
        ]);
    }

    public function test_a_scan_code_is_left_for_a_person(): void
    {
        Http::fake([
            'http://wa-web.test/status' => Http::response(['connected' => false, 'qr' => 'data:image/png', 'phone' => null], 200),
        ]);

        $this->withHeaders(['X-N8N-Secret' => 'n8n-secret'])
            ->postJson('/api/integrations/whatsapp-bridge/repair')
            ->assertOk()
            ->assertJson(['fixed' => false, 'action' => 'needs_scan']);

        Http::assertNotSent(fn ($sent): bool => str_contains($sent->url(), '/repair'));
    }

    public function test_a_linked_bridge_is_left_alone(): void
    {
        Http::fake([
            'http://wa-web.test/status' => Http::response(['connected' => true, 'qr' => null, 'phone' => '963944000111'], 200),
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'n8n-secret'])
            ->postJson('/api/integrations/whatsapp-bridge/repair')
            ->assertOk()
            ->assertJson(['fixed' => true, 'action' => 'already_up']);
    }

    public function test_a_stuck_bridge_is_asked_to_restart(): void
    {
        Http::fake([
            'http://wa-web.test/status' => Http::response(['connected' => false, 'qr' => null, 'phone' => null], 200),
            'http://wa-web.test/repair' => Http::response(['restarting' => true], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->withHeaders(['X-N8N-Secret' => 'n8n-secret'])
            ->postJson('/api/integrations/whatsapp-bridge/repair')
            ->assertOk()
            ->assertJson(['fixed' => true, 'action' => 'process_restart']);

        Http::assertSent(fn ($sent): bool => $sent->url() === 'http://wa-web.test/repair'
            && $sent->hasHeader('X-Webhook-Secret', 'web-secret'));
        Http::assertSent(fn ($sent): bool => str_contains($sent->url(), 'api.telegram.org/botdev-token/sendMessage')
            && str_contains((string) $sent['text'], 'تم إصلاح جسر واتساب'));
    }

    public function test_the_repair_route_rejects_a_missing_secret(): void
    {
        $this->postJson('/api/integrations/whatsapp-bridge/repair')->assertUnauthorized();
    }
}
