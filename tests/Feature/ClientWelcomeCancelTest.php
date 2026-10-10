<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientWelcomeCancelTest extends TestCase
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
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-10 22:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_period_returns_the_welcome(): void
    {
        $phone = '963944000111';
        Client::factory()->create([
            'name' => 'نور',
            'phone' => '+'.$phone,
            'company_name' => 'النور',
            'telegram_user_id' => 'wa:'.$phone,
            'odoo_lead_id' => 9,
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => $phone,
                'message_id' => 'dot-1',
                'text' => '.',
            ])
            ->assertOk();

        Http::assertSent(fn (Request $request): bool => str_contains((string) data_get($request->data(), 'text'), 'أهلين نور'));
    }

    public function test_the_client_cannot_cancel_a_request_after_24_hours_from_the_invoice(): void
    {
        $phone = '963944000112';
        $client = Client::factory()->create([
            'name' => 'نور',
            'phone' => '+'.$phone,
            'company_name' => 'النور',
            'telegram_user_id' => 'wa:'.$phone,
            'odoo_lead_id' => 9,
        ]);
        $request = ServiceRequest::factory()->create([
            'client_id' => $client->id,
            'status' => RequestStatus::AwaitingPayment,
            'number' => 'REQ-2026-000041',
            'invoice_sent_at' => now()->subHours(25),
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => $phone,
                'message_id' => 'cancel-late',
                'text' => 'بدي ألغي الطلب',
            ])
            ->assertOk();

        $this->assertSame(RequestStatus::AwaitingPayment, $request->fresh()->status);
        Http::assertSent(fn (Request $sent): bool => str_contains((string) data_get($sent->data(), 'text'), '٢٤ ساعة'));
    }

    public function test_the_client_can_cancel_within_24_hours_of_the_invoice(): void
    {
        $client = Client::factory()->create([
            'telegram_user_id' => '555001',
            'odoo_lead_id' => 9,
        ]);
        $request = ServiceRequest::factory()->create([
            'client_id' => $client->id,
            'status' => RequestStatus::Submitted,
            'invoice_sent_at' => now()->subHours(2),
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson('/api/bot/telegram/requests/'.$request->number.'/cancel', [
                'telegram_user_id' => '555001',
            ])
            ->assertOk();

        $this->assertSame(RequestStatus::Cancelled, $request->fresh()->status);
    }

    public function test_telegram_cancel_is_refused_after_24_hours(): void
    {
        $client = Client::factory()->create([
            'telegram_user_id' => '555002',
            'odoo_lead_id' => 9,
        ]);
        $request = ServiceRequest::factory()->create([
            'client_id' => $client->id,
            'status' => RequestStatus::Submitted,
            'invoice_sent_at' => now()->subHours(30),
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson('/api/bot/telegram/requests/'.$request->number.'/cancel', [
                'telegram_user_id' => '555002',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'ما فينا نلغي الطلب بعد ٢٤ ساعة من إرسال الفاتورة.');

        $this->assertSame(RequestStatus::Submitted, $request->fresh()->status);
    }
}
