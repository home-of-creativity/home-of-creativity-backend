<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\BotDraft;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Support\ClientChannelGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesOdooDocuments;
use Tests\TestCase;

class BotDraftAndPreviewTest extends TestCase
{
    use FakesOdooDocuments;
    use RefreshDatabase;

    public function test_client_drafts_survive_in_laravel_and_clear_when_empty(): void
    {
        $payload = [
            'user_data' => ['title' => 'هوية مطعم', 'attachments' => [['file_id' => 'AgAC-1', 'file_name' => 'photo.jpg']]],
            'conversations' => ['client_compose' => 1],
        ];

        $this->clientBot()->putJson('/api/bot/telegram/drafts/4242', ['payload' => $payload])
            ->assertOk()
            ->assertJsonPath('data.stored', true);

        ClientChannelGate::setTelegramEnabled(false);
        $this->clientBot()->getJson('/api/bot/telegram/drafts')
            ->assertOk()
            ->assertJsonPath('data.0.telegram_user_id', '4242')
            ->assertJsonPath('data.0.payload.user_data.title', 'هوية مطعم')
            ->assertJsonPath('data.0.payload.conversations.client_compose', 1);

        $this->clientBot()->putJson('/api/bot/telegram/drafts/4242', ['payload' => ['user_data' => [], 'conversations' => []]])
            ->assertOk()
            ->assertJsonPath('data.stored', false);
        $this->assertSame(0, BotDraft::query()->count());

        $this->clientBot()->putJson('/api/bot/telegram/drafts/not-a-user', ['payload' => $payload])->assertStatus(422);
        $this->withHeaders(['X-Webhook-Secret' => 'wrong'])->getJson('/api/bot/telegram/drafts')->assertUnauthorized();
    }

    public function test_sales_see_the_quotation_preview_before_the_client_receives_it(): void
    {
        $this->fakeOdooDocuments();
        config(['services.telegram.bot_token' => 'client-token']);
        Http::preventStrayRequests();
        Http::fake(array_merge($this->odooDocumentsHttpFake(), [
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5, 'document' => ['file_id' => 'doc-1']]], 200),
        ]));
        Employee::factory()->sales()->create(['telegram_user_id' => 'sales-preview']);
        $client = Client::factory()->create(['telegram_user_id' => '777000111', 'company_name' => 'مطعم الشام']);
        $request = ServiceRequest::factory()->for($client)->create(['status' => RequestStatus::Submitted]);

        $preview = $this->staffBot()->postJson('/api/bot/staff/quotation/preview', [
            'telegram_user_id' => 'sales-preview',
            'request_number' => $request->number,
            'amount' => 400,
            'notes' => 'تصميم شعار ودليل استخدام',
        ])->assertOk()->json('data');

        $this->assertStringContainsString('معاينة عرض السعر — لم يصل للزبون بعد', $preview['card']);
        $this->assertStringContainsString('المبلغ: 400.00 USD', $preview['card']);
        $this->assertNotNull($preview['pdf_base64']);
        $this->assertSame(0, Quotation::query()->count());
        $this->assertSame(RequestStatus::Submitted, $request->fresh()->status);
        Http::assertNotSent(fn (Request $sent): bool => str_contains($sent->url(), 'api.telegram.org'));

        $this->staffBot()->postJson('/api/bot/staff/quotation/confirm', [
            'telegram_user_id' => 'sales-preview',
            'token' => $preview['token'],
        ])->assertOk()
            ->assertJsonPath('data.quotation_version', 1);

        $this->assertSame(RequestStatus::QuotationSent, $request->fresh()->status);
        $this->assertSame('301', $request->fresh()->odoo_quotation_id);
        $this->assertSame(1, $this->odooCreates('sale.order'));
        Http::assertSent(fn (Request $sent): bool => str_contains($sent->url(), 'sendDocument'));

        $this->staffBot()->postJson('/api/bot/staff/quotation/confirm', [
            'telegram_user_id' => 'sales-preview',
            'token' => $preview['token'],
        ])->assertStatus(422)
            ->assertJsonPath('errors.token.0', 'انتهت صلاحية المعاينة. أدخل المبلغ من جديد.');
    }

    public function test_editing_the_amount_discards_the_preview(): void
    {
        Employee::factory()->sales()->create(['telegram_user_id' => 'sales-edit']);
        $request = ServiceRequest::factory()->create(['status' => RequestStatus::Submitted]);

        $preview = $this->staffBot()->postJson('/api/bot/staff/quotation/preview', [
            'telegram_user_id' => 'sales-edit',
            'request_number' => $request->number,
            'amount' => 150,
        ])->assertOk()->json('data');

        $this->assertIsString($preview['pdf_base64']);
        $this->assertStringStartsWith('%PDF', base64_decode((string) $preview['pdf_base64'], true) ?: '');
        $this->assertStringNotContainsString('أودو لم يرد', $preview['card']);
        $token = $preview['token'];

        $this->staffBot()->postJson('/api/bot/staff/quotation/discard', [
            'telegram_user_id' => 'sales-edit',
            'token' => $token,
        ])->assertOk();

        $this->staffBot()->postJson('/api/bot/staff/quotation/confirm', [
            'telegram_user_id' => 'sales-edit',
            'token' => $token,
        ])->assertStatus(422);
        $this->assertSame(0, Quotation::query()->count());
    }

    private function odooCreates(string $model): int
    {
        return collect(Http::recorded())->filter(function (array $pair) use ($model): bool {
            $args = $pair[0]->data()['params']['args'] ?? [];

            return ($args[3] ?? null) === $model && ($args[4] ?? null) === 'create';
        })->count();
    }

    private function clientBot()
    {
        return $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot', 'Accept' => 'application/json']);
    }

    private function staffBot()
    {
        return $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff', 'Accept' => 'application/json']);
    }
}
