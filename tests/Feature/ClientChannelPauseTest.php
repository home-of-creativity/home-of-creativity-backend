<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Services\TelegramNotifier;
use App\Support\ClientChannelGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientChannelPauseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_secret' => 'change-me-bot',
            'services.telegram.staff_bot_secret' => 'change-me-staff',
            'services.telegram.bot_token' => 'tg-token',
            'services.whatsapp.token' => 'wa-token',
            'services.whatsapp.phone_number_id' => '555',
            'services.whatsapp.verify_token' => 'verify-me',
            'services.whatsapp.app_secret' => 'app-secret',
            'services.whatsapp.graph_base' => 'https://graph.facebook.com/v21.0',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);
    }

    public function test_ops_settings_default_to_both_channels_on(): void
    {
        $this->actingAdmin()
            ->getJson('/api/admin/ops-settings')
            ->assertOk()
            ->assertJsonPath('data.telegram_enabled', true)
            ->assertJsonPath('data.whatsapp_enabled', true);
    }

    public function test_guest_cannot_pause_channels(): void
    {
        $this->putJson('/api/admin/ops-settings/client-channels', [
            'telegram_enabled' => false,
            'whatsapp_enabled' => true,
        ])->assertUnauthorized();
    }

    public function test_admin_can_pause_telegram_without_stopping_whatsapp_or_staff_bot(): void
    {
        $this->actingAdmin()
            ->putJson('/api/admin/ops-settings/client-channels', [
                'telegram_enabled' => false,
                'whatsapp_enabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.telegram_enabled', false)
            ->assertJsonPath('data.whatsapp_enabled', true);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot', 'Accept' => 'application/json'])
            ->getJson('/api/bot/telegram/me?telegram_user_id=tg-paused')
            ->assertStatus(503)
            ->assertJsonPath('code', 'channel_paused')
            ->assertJsonPath('message', ClientChannelGate::TELEGRAM_PAUSED_MESSAGE);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff', 'Accept' => 'application/json'])
            ->getJson('/api/bot/staff/me?telegram_user_id=1')
            ->assertNotFound();

        $notifier = app(TelegramNotifier::class);
        $this->assertFalse($notifier->canReachClient('12345'));
        $this->assertTrue($notifier->canReachClient('wa:963911111111'));

        $notifier->send('wa:963911111111', 'whatsapp still open');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'graph.facebook.com')
            && data_get($request->data(), 'text.body') === 'whatsapp still open');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org'));
    }

    public function test_paused_whatsapp_replies_once_and_does_not_link_a_client(): void
    {
        ClientChannelGate::setWhatsAppEnabled(false);

        $this->signedWhatsAppPost($this->textPayload('963911111111', 'Nour', 'مرحبا', 'wamid.pause-1'))
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->assertNull(Client::query()->where('telegram_user_id', 'wa:963911111111')->first());

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/555/messages')
            && data_get($request->data(), 'type') === 'text'
            && data_get($request->data(), 'text.body') === ClientChannelGate::WHATSAPP_PAUSED_MESSAGE);

        $this->signedWhatsAppPost($this->textPayload('963911111111', 'Nour', 'مرحبا', 'wamid.pause-2'))
            ->assertOk();

        $pauseNotices = collect(Http::recorded())
            ->filter(fn ($pair): bool => data_get($pair[0]->data(), 'text.body') === ClientChannelGate::WHATSAPP_PAUSED_MESSAGE);

        $this->assertCount(1, $pauseNotices);

        $this->get('/api/bot/whatsapp/webhook?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'verify-me',
            'hub.challenge' => 'still-ok',
        ]))->assertOk()->assertSee('still-ok');
    }

    public function test_paused_telegram_does_not_send_client_messages(): void
    {
        ClientChannelGate::setTelegramEnabled(false);

        app(TelegramNotifier::class)->send('12345', 'should not send');

        Http::assertNothingSent();
    }

    private function actingAdmin()
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function signedWhatsAppPost(array $payload)
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
}
