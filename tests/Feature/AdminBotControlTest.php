<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\OpsExpense;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminBotControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_overview_clients_operations_and_finance(): void
    {
        config([
            'services.telegram.admin_telegram_ids' => ['8260054672'],
            'services.gemini.e2e_stub' => true,
        ]);

        $client = Client::factory()->create([
            'name' => 'شركة النور',
            'company_name' => 'النور',
            'phone' => '+963900000001',
        ]);
        $request = ServiceRequest::factory()->for($client)->create([
            'title' => 'هوية بصرية',
            'status' => RequestStatus::InProgress,
            'amount_paid' => 400,
            'amount_remaining' => 200,
            'work_plan' => [
                'source' => 'cache',
                'operations' => [[
                    'department' => 'design',
                    'brief' => 'تصميم الشعار',
                    'hours' => 24,
                    'priority_label' => 'عالية',
                    'employee_name' => 'مصمم أول',
                ]],
            ],
        ]);
        Invoice::query()->create([
            'request_id' => $request->id,
            'invoice_number' => 'INV-ADMIN-1',
            'amount' => 400,
            'kind' => 'received',
            'status' => 'issued',
            'issued_at' => now(),
        ]);

        $this->adminBot()->getJson('/api/bot/admin/overview?telegram_user_id=8260054672')
            ->assertOk()
            ->assertJsonPath('data.clients', 1)
            ->assertJsonPath('data.in_progress', 1)
            ->assertJsonPath('data.revenue_paid', 400);

        $this->adminBot()->getJson('/api/bot/admin/clients?telegram_user_id=8260054672')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'شركة النور')
            ->assertJsonPath('data.0.open_count', 1);

        $this->adminBot()->getJson("/api/bot/admin/clients/{$client->id}?telegram_user_id=8260054672")
            ->assertOk()
            ->assertJsonPath('data.requests.0.title', 'هوية بصرية');

        $this->adminBot()->getJson('/api/bot/admin/operations?telegram_user_id=8260054672')
            ->assertOk()
            ->assertJsonPath('data.0.operations.0.employee_name', 'مصمم أول');

        $this->adminBot()->postJson('/api/bot/admin/operations/rebuild', [
            'telegram_user_id' => '8260054672',
            'request_number' => $request->number,
        ])->assertOk()
            ->assertJsonPath('data.operations.0.department', 'design');

        $this->adminBot()->postJson('/api/bot/admin/expenses', [
            'telegram_user_id' => '8260054672',
            'amount' => 50,
            'category' => 'برامج',
            'note' => 'اشتراك',
        ])->assertCreated()
            ->assertJsonPath('data.finance.expenses', 50)
            ->assertJsonPath('data.finance.net', 350);

        $this->assertSame(1, OpsExpense::query()->count());

        $this->adminBot()->getJson('/api/bot/admin/finance?telegram_user_id=8260054672')
            ->assertOk()
            ->assertJsonPath('data.revenue_paid', 400)
            ->assertJsonPath('data.expense_rows.0.category', 'برامج');
    }

    public function test_admin_can_update_clickup_task_status_priority_and_due(): void
    {
        config([
            'services.telegram.admin_telegram_ids' => ['8260054672'],
            'services.clickup.token' => 'pk_test',
            'services.clickup.lists.design' => 'list-design',
        ]);
        Http::fake([
            'https://api.clickup.com/api/v2/task/tsk-1' => Http::response(['id' => 'tsk-1'], 200),
        ]);

        $this->adminBot()->postJson('/api/bot/admin/task', [
            'telegram_user_id' => '8260054672',
            'task_id' => 'tsk-1',
            'status' => 'in progress',
            'priority' => 1,
            'due_hours' => 12,
        ])->assertOk()
            ->assertJsonPath('data.updated', true);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/task/tsk-1') || $request->method() !== 'PUT') {
                return false;
            }
            $payload = $request->data();

            return ($payload['status'] ?? null) === 'in progress'
                && (int) ($payload['priority'] ?? 0) === 1
                && isset($payload['due_date']);
        });
    }

    public function test_non_admin_cannot_open_desk(): void
    {
        config(['services.telegram.admin_telegram_ids' => ['8260054672']]);

        $this->adminBot()->getJson('/api/bot/admin/overview?telegram_user_id=99')
            ->assertForbidden();
        $this->adminBot()->postJson('/api/bot/admin/expenses', [
            'telegram_user_id' => '99',
            'amount' => 10,
            'category' => 'أخرى',
        ])->assertForbidden();
    }

    private function adminBot()
    {
        return $this->withHeaders(['X-Webhook-Secret' => 'change-me-admin', 'Accept' => 'application/json']);
    }
}
