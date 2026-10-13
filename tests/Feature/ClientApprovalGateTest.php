<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A client's yes to a quotation waits for an admin before the sales order and the payment steps. */
class ClientApprovalGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.telegram.bot_token' => 'client-token',
            'services.telegram.admin_bot_token' => 'admin-token',
            'services.telegram.admin_telegram_ids' => ['900'],
        ]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
    }

    private function quoted(string $chat = 'tg-gate'): ServiceRequest
    {
        $client = Client::factory()->create(['telegram_user_id' => $chat]);
        $request = ServiceRequest::factory()->for($client)->create(['status' => RequestStatus::QuotationSent, 'quotation_amount' => 300]);
        Quotation::query()->create(['request_id' => $request->id, 'version' => 1, 'amount' => 300, 'sent_at' => now()]);

        return $request;
    }

    private function clientApproves(ServiceRequest $request, string $chat = 'tg-gate'): void
    {
        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$request->number}/approve", ['telegram_user_id' => $chat])
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_admin_approval')
            ->assertJsonPath('awaiting_admin', true);
    }

    public function test_admins_get_a_confirm_button_and_the_client_gets_no_payment_steps_yet(): void
    {
        $request = $this->quoted();
        $this->clientApproves($request);

        Http::assertSent(fn (Request $sent): bool => str_contains($sent->url(), 'botadmin-token/sendMessage')
            && $sent['chat_id'] === '900'
            && str_contains((string) $sent['text'], 'ينتظر اعتمادك')
            && str_contains(json_encode($sent['reply_markup'], JSON_UNESCAPED_UNICODE) ?: '', 'qok:'));
        Http::assertNotSent(fn (Request $sent): bool => str_contains($sent->url(), 'botclient-token/') && str_contains((string) ($sent['caption'] ?? $sent['text'] ?? ''), 'تعليمات الدفع'));
    }

    public function test_the_dashboard_confirms_and_the_client_gets_the_payment_steps(): void
    {
        $request = $this->quoted();
        $this->clientApproves($request);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->postJson("/api/admin/requests/{$request->id}/client-approval", ['decision' => 'confirm'])
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_payment');

        $this->assertEqualsWithDelta(300, (float) $request->fresh()->amount_total, 0.01);
        Http::assertSent(fn (Request $sent): bool => str_contains($sent->url(), 'botclient-token/send')
            && str_contains((string) ($sent['caption'] ?? $sent['text'] ?? ''), 'تعليمات الدفع'));
        $this->postJson("/api/admin/requests/{$request->id}/client-approval", ['decision' => 'confirm'])->assertStatus(422);
    }

    public function test_a_declined_approval_rejects_the_quotation_and_tells_the_client(): void
    {
        $request = $this->quoted();
        $this->clientApproves($request);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->postJson("/api/admin/requests/{$request->id}/client-approval", ['decision' => 'decline'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
        $this->postJson("/api/admin/requests/{$request->id}/client-approval", ['decision' => 'decline', 'reason' => 'السعر يحتاج تعديل'])
            ->assertOk()
            ->assertJsonPath('data.status', 'quotation_rejected');

        Http::assertSent(fn (Request $sent): bool => str_contains($sent->url(), 'botclient-token/sendMessage')
            && str_contains((string) $sent['text'], 'عرض محدّث'));
    }

    public function test_only_staff_with_the_operation_may_confirm(): void
    {
        $request = $this->quoted();
        $this->clientApproves($request);
        $role = Role::query()->create(['name' => 'مبيعات', 'abilities' => ['ops.requests.view', 'ops.requests.quotation']]);
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id]));

        $this->postJson("/api/admin/requests/{$request->id}/client-approval", ['decision' => 'confirm'])->assertForbidden();
        $this->assertSame(RequestStatus::AwaitingAdminApproval, $request->fresh()->status);
    }

    public function test_sales_entering_a_call_still_wait_for_the_admin(): void
    {
        $this->mock(\App\Services\DevBeat::class, fn ($mock) => $mock->shouldReceive('ageSeconds')->andReturn(null));
        $request = $this->quoted();
        $role = Role::query()->create(['name' => 'مبيعات', 'abilities' => ['ops.requests.view', 'ops.requests.quotation']]);
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id]));

        $this->postJson("/api/admin/requests/{$request->id}/client-decision", ['decision' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_admin_approval');
    }

    public function test_the_admin_bot_button_confirms(): void
    {
        $request = $this->quoted();
        $this->clientApproves($request);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-admin'])
            ->postJson('/api/bot/admin/client-approvals/confirm', ['telegram_user_id' => '901', 'request_number' => $request->number])
            ->assertForbidden();
        $this->withHeaders(['X-Webhook-Secret' => 'change-me-admin'])
            ->postJson('/api/bot/admin/client-approvals/confirm', ['telegram_user_id' => '900', 'request_number' => $request->number])
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_payment');

        $this->assertDatabaseHas('request_status_history', [
            'request_id' => $request->id,
            'to_status' => 'awaiting_payment',
            'actor' => 'admin:telegram:900',
        ]);
    }
}
