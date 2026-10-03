<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\DriveDelivery;
use App\Models\OdooLeadNote;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\DevBeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientFallbackTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.bot_token' => 'client-token']);
        $this->mock(DevBeat::class, fn ($mock) => $mock->shouldReceive('ageSeconds')->andReturn(null));
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
        $this->admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($this->admin);
    }

    public function test_staff_record_an_approval_by_phone_when_the_client_bot_is_down(): void
    {
        $request = $this->quotedRequest('tg-call-1');

        $this->getJson("/api/admin/requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.client_bot_reachable', false);

        $this->postJson("/api/admin/requests/{$request->number}/client-decision", ['decision' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonPath('data.manual_decisions.0.to_status', 'awaiting_payment')
            ->assertJsonPath('message', 'سُجّل قرار الزبون يدوياً.');

        $this->assertDatabaseHas('request_status_history', [
            'request_id' => $request->id,
            'to_status' => 'awaiting_payment',
            'actor' => 'manual:'.$this->admin->id,
        ]);
        $this->assertTrue(OdooLeadNote::query()->get()->contains(
            fn (OdooLeadNote $note): bool => str_contains($note->body, 'بواسطة: اللوحة نيابة عن الزبون'),
        ));
    }

    public function test_the_fallback_is_refused_while_the_client_bot_is_alive(): void
    {
        $this->mock(DevBeat::class, fn ($mock) => $mock->shouldReceive('ageSeconds')->with('client')->andReturn(30));
        $request = $this->quotedRequest('123456789');

        $this->postJson("/api/admin/requests/{$request->number}/client-decision", ['decision' => 'approve'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.decision.0', 'بوت العميل يعمل، فيسجّل الزبون قراره بنفسه.');

        $this->assertSame(RequestStatus::QuotationSent, $request->fresh()->status);
    }

    public function test_reject_needs_what_the_client_said(): void
    {
        $request = $this->quotedRequest('tg-call-2');

        $this->postJson("/api/admin/requests/{$request->number}/client-decision", ['decision' => 'reject'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'اكتب ما قاله الزبون.');

        $this->postJson("/api/admin/requests/{$request->number}/client-decision", [
            'decision' => 'reject',
            'reason' => 'السعر أعلى من الميزانية',
        ])->assertOk()
            ->assertJsonPath('data.status', 'quotation_rejected');
    }

    public function test_accepting_the_delivery_by_phone_approves_the_sent_files(): void
    {
        $client = Client::factory()->create(['telegram_user_id' => null]);
        $request = ServiceRequest::factory()->for($client)->create(['status' => RequestStatus::ReadyForReview]);
        $delivery = DriveDelivery::query()->create([
            'request_id' => $request->id,
            'drive_file_id' => 'file-1',
            'name' => 'logo.png',
            'sent_at' => now(),
        ]);

        $this->postJson("/api/admin/requests/{$request->number}/client-decision", ['decision' => 'complete'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertNotNull($delivery->fresh()->client_approved_at);
    }

    public function test_finance_summary_and_expense_work_from_the_dashboard(): void
    {
        $this->getJson('/api/admin/finance')
            ->assertOk()
            ->assertJsonPath('data.expenses', 0);

        $this->postJson('/api/admin/finance/expenses', ['amount' => 0, 'category' => 'مكتب'])
            ->assertUnprocessable();

        $this->postJson('/api/admin/finance/expenses', [
            'amount' => 45.5,
            'category' => 'مكتب',
            'note' => 'ورق طباعة',
        ])->assertCreated()
            ->assertJsonPath('data.expenses', 45.5)
            ->assertJsonPath('data.expense_rows.0.note', 'ورق طباعة');
    }

    private function quotedRequest(string $telegramId): ServiceRequest
    {
        $client = Client::factory()->create(['telegram_user_id' => $telegramId, 'company_name' => 'شركة المكالمة']);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::QuotationSent,
            'quotation_amount' => 120,
        ]);
        Quotation::query()->create([
            'request_id' => $request->id,
            'version' => 1,
            'amount' => 120,
            'sent_at' => now(),
        ]);

        return $request;
    }
}
