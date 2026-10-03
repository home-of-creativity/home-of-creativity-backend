<?php

namespace Tests\Feature;

use App\Actions\ProvisionClickUpTasks;
use App\Enums\RequestStatus;
use App\Models\ClickUpTask;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClickUpRetryTest extends TestCase
{
    use RefreshDatabase;

    private bool $contentListDown = true;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.clickup.token' => 'pk_test',
            'services.clickup.list_id' => 'sales-list',
            'services.clickup.lists.sales' => 'sales-list',
            'services.clickup.lists.design' => 'design-list',
            'services.clickup.lists.content' => 'content-list',
            'services.n8n.webhook_url' => '',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.clickup.com/api/v2/list/design-list/task' => Http::response(['id' => 'cu-design'], 200),
            'https://api.clickup.com/api/v2/list/content-list/task' => function () {
                return $this->contentListDown
                    ? Http::response(['err' => 'down'], 503)
                    : Http::response(['id' => 'cu-content'], 200);
            },
            'https://api.clickup.com/api/v2/list/sales-list/task' => Http::response(['id' => 'cu-sales'], 200),
        ]);
    }

    public function test_a_partial_failure_is_retried_without_duplicating_the_created_department(): void
    {
        $request = $this->paidRequest();

        $fresh = app(ProvisionClickUpTasks::class)->handle($request, (string) Str::uuid());

        $this->assertSame(['design'], $fresh->clickupTasks->map(fn (ClickUpTask $task): string => $task->task_type->value)->all());
        $this->assertNotNull($fresh->clickup_error);
        $this->assertSame(1, $fresh->clickup_attempts);
        $this->assertSame(RequestStatus::PaymentConfirmed, $fresh->status);

        $this->contentListDown = false;
        $this->artisan('clickup:retry-tasks')->assertSuccessful();

        $done = $request->fresh(['clickupTasks']);
        $this->assertNull($done->clickup_error);
        $this->assertSame(0, $done->clickup_attempts);
        $this->assertSame(RequestStatus::InProgress, $done->status);
        $this->assertEqualsCanonicalizing(['design', 'content'], $done->clickupTasks->map(fn (ClickUpTask $task): string => $task->task_type->value)->all());
        $this->assertSame(1, $this->postsTo('design-list'));
    }

    public function test_dashboard_retry_reports_when_clickup_is_still_down(): void
    {
        $request = $this->paidRequest();
        app(ProvisionClickUpTasks::class)->handle($request, (string) Str::uuid());
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->postJson("/api/admin/requests/{$request->number}/provision-clickup")
            ->assertOk()
            ->assertJsonPath('data.clickup_attempts', 2)
            ->assertJsonPath('message', 'ما زال ClickUp لا يرد. ستُعاد المحاولة تلقائياً.');

        $this->contentListDown = false;
        $this->postJson("/api/admin/requests/{$request->number}/provision-clickup")
            ->assertOk()
            ->assertJsonPath('data.clickup_error', null)
            ->assertJsonPath('message', 'تم إنشاء مهام ClickUp.');
    }

    public function test_payment_creates_tasks_through_laravel_when_n8n_is_empty(): void
    {
        $this->contentListDown = false;
        config(['services.gemini.e2e_stub' => true]);
        $request = ServiceRequest::factory()->create([
            'status' => RequestStatus::AwaitingPayment,
            'quotation_amount' => 200,
            'amount_total' => 200,
            'work_plan' => ['operations' => [['department' => 'design', 'brief' => 'تصميم الهوية', 'hours' => 6]]],
        ]);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->postJson("/api/admin/requests/{$request->number}/confirm-payment", [
            'payment_method' => 'cash',
            'amount' => 200,
        ])->assertOk();

        $done = $request->fresh(['clickupTasks']);
        $this->assertSame(RequestStatus::InProgress, $done->status);
        $this->assertSame('cu-design', $done->clickupTasks->firstWhere('task_type.value', 'design')?->clickup_task_id);
        Http::assertNotSent(fn (Request $sent): bool => str_contains($sent->url(), 'n8n'));
    }

    private function paidRequest(): ServiceRequest
    {
        return ServiceRequest::factory()->create([
            'status' => RequestStatus::PaymentConfirmed,
            'paid_at' => now(),
            'work_plan' => ['operations' => [
                ['department' => 'design', 'brief' => 'تصميم الشعار', 'hours' => 6],
                ['department' => 'content', 'brief' => 'كتابة المنشورات', 'hours' => 4],
            ]],
        ]);
    }

    private function postsTo(string $list): int
    {
        return collect(Http::recorded())
            ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), "/list/{$list}/task"))
            ->count();
    }
}
