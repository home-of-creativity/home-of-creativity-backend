<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
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

/**
 * A client's yes to a quotation sends the payment steps at once and waits for an admin out
 * of the client's sight; the admin's approval (or a confirmed payment) completes it.
 */
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
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
            // Nothing else leaves the test (Gemini, ClickUp, Drive).
            '*' => Http::response([], 200),
        ]);
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
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonMissingPath('awaiting_admin');
    }

    public function test_the_client_gets_the_payment_steps_at_once_and_admins_get_a_button(): void
    {
        $request = $this->quoted();
        $this->clientApproves($request);

        // The client sees an ordinary request awaiting payment, with the amount and QR.
        Http::assertSent(fn (Request $sent): bool => str_contains($sent->url(), 'botclient-token/send')
            && str_contains((string) ($sent['caption'] ?? $sent['text'] ?? ''), 'تعليمات الدفع'));
        Http::assertNotSent(fn (Request $sent): bool => str_contains($sent->url(), 'botclient-token/')
            && str_contains((string) ($sent['caption'] ?? $sent['text'] ?? ''), 'اعتماد'));
        $this->assertTrue($request->fresh()->awaitsAdminApproval());
        $this->assertSame(RequestStatus::AwaitingAdminApproval, $request->fresh()->status);

        // The client's list shows an ordinary request awaiting payment that takes a receipt.
        $mine = $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->getJson('/api/bot/telegram/requests?telegram_user_id=tg-gate')
            ->assertOk()
            ->json('data.0');
        $this->assertSame('awaiting_payment', $mine['status']);
        $this->assertSame('بانتظار الدفع', $mine['status_label']);
        $this->assertTrue($request->fresh()->acceptsReceiptUpload());

        Http::assertSent(fn (Request $sent): bool => str_contains($sent->url(), 'botadmin-token/sendMessage')
            && $sent['chat_id'] === '900'
            && str_contains((string) $sent['text'], 'ينتظر اعتمادك')
            && str_contains(json_encode($sent['reply_markup'], JSON_UNESCAPED_UNICODE) ?: '', 'qok:'));
    }

    public function test_the_dashboard_approves_once(): void
    {
        $request = $this->quoted();
        $this->clientApproves($request);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->getJson("/api/admin/requests/{$request->id}")->assertOk()->assertJsonPath('data.admin_approval_pending', true);
        $this->postJson("/api/admin/requests/{$request->id}/client-approval")
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonPath('data.admin_approval_pending', false);

        $this->assertNotNull($request->fresh()->admin_approved_at);
        $this->postJson("/api/admin/requests/{$request->id}/client-approval")->assertStatus(422);
    }

    public function test_only_staff_with_the_operation_may_approve(): void
    {
        $request = $this->quoted();
        $this->clientApproves($request);
        $role = Role::query()->create(['name' => 'مبيعات', 'abilities' => ['ops.requests.view', 'ops.requests.quotation']]);
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id]));

        $this->postJson("/api/admin/requests/{$request->id}/client-approval")->assertForbidden();
        $this->assertTrue($request->fresh()->awaitsAdminApproval());
    }

    public function test_a_confirmed_payment_approves_the_request(): void
    {
        $request = $this->quoted();
        $this->clientApproves($request);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->postJson("/api/admin/requests/{$request->id}/confirm-payment", ['payment_method' => PaymentMethod::Cash->value, 'amount' => 150])
            ->assertOk();

        $fresh = $request->fresh();
        $this->assertFalse($fresh->awaitsAdminApproval());
        $this->assertSame('admin:payment', $fresh->admin_approved_by);
        $this->assertSame(RequestStatus::PaymentConfirmed, $fresh->status);
    }

    public function test_sales_entering_a_call_still_wait_for_the_admin(): void
    {
        $this->mock(\App\Services\DevBeat::class, fn ($mock) => $mock->shouldReceive('ageSeconds')->andReturn(null));
        $request = $this->quoted();
        $role = Role::query()->create(['name' => 'مبيعات', 'abilities' => ['ops.requests.view', 'ops.requests.quotation']]);
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id]));

        $this->postJson("/api/admin/requests/{$request->id}/client-decision", ['decision' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_admin_approval')
            ->assertJsonPath('data.admin_approval_pending', true);
    }

    public function test_the_admin_bot_button_approves(): void
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

        $this->assertSame('admin:telegram:900', $request->fresh()->admin_approved_by);
    }
}
