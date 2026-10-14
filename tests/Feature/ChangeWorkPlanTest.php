<?php

namespace Tests\Feature;

use App\Enums\ClickUpTaskType;
use App\Enums\EmployeeProfession;
use App\Enums\RequestStatus;
use App\Models\ClickUpTask;
use App\Models\Client;
use App\Models\Employee;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** «تغيير الخطة» and «تمديد المدة» from the request page, both carried to ClickUp. */
class ChangeWorkPlanTest extends TestCase
{
    use RefreshDatabase;

    private ServiceRequest $request;

    private Employee $designer;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.clickup.token' => 'pk_test',
            'services.clickup.lists.sales' => 'list-sales',
            'services.clickup.lists.design' => 'list-design',
            'services.clickup.lists.content' => 'list-content',
            'services.clickup.lists.programming' => 'list-programming',
            'services.telegram.bot_token' => 'client-token',
        ]);
        Http::fake([
            'https://api.clickup.com/api/v2/list/*/task' => Http::response(['id' => 'task-new'], 200),
            'https://api.clickup.com/*' => Http::response(['ok' => true], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
            '*' => Http::response([], 200),
        ]);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->designer = Employee::factory()->create(['name' => 'ريم', 'profession' => EmployeeProfession::Design, 'clickup_user_id' => '501']);
        $client = Client::factory()->create(['telegram_user_id' => 'tg-plan']);
        $this->request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::InProgress,
            'paid_at' => now()->subDay(),
            'plan_confirmed_at' => now()->subDay(),
            'client_due_at' => now()->addDays(3),
            'work_plan' => ['operations' => [
                ['department' => 'design', 'brief' => 'شعار', 'hours' => 8, 'priority' => 3, 'due_at' => now()->addDay()->toIso8601String(), 'clickup_user_id' => '499'],
                ['department' => 'content', 'brief' => 'نصوص', 'hours' => 6, 'priority' => 3, 'due_at' => now()->addDay()->toIso8601String()],
            ]],
        ]);
        foreach (['design' => 'task-design', 'content' => 'task-content'] as $type => $id) {
            ClickUpTask::query()->create([
                'request_id' => $this->request->id,
                'task_type' => ClickUpTaskType::from($type),
                'clickup_task_id' => $id,
                'clickup_user_id' => $type === 'design' ? '499' : null,
                'integration_key' => "plan-{$type}",
                'status' => 'to do',
                'planned_hours' => 8,
            ]);
        }
    }

    public function test_the_plan_lists_every_department_with_its_staff(): void
    {
        $plan = $this->getJson("/api/admin/requests/{$this->request->id}/work-plan")->assertOk()->json('data');

        $this->assertSame(['design', 'content', 'programming'], array_column($plan['departments'], 'department'));
        $this->assertSame([true, true, false], array_column($plan['departments'], 'included'));
        $this->assertSame('ريم', $plan['departments'][0]['employees'][0]['name']);
        $this->assertTrue($plan['confirmed']);
    }

    public function test_changing_the_plan_updates_adds_and_closes_clickup_tasks(): void
    {
        $this->putJson("/api/admin/requests/{$this->request->id}/work-plan", [
            'operations' => [
                ['department' => 'design', 'brief' => 'شعار وهوية كاملة', 'hours' => 10, 'priority' => 1, 'employee_id' => $this->designer->id],
                ['department' => 'programming', 'brief' => 'صفحة هبوط', 'hours' => 16, 'priority' => 2],
            ],
            'photography_sessions' => 2,
        ])->assertOk()->assertJsonPath('clickup_errors', []);

        $fresh = $this->request->fresh();
        $this->assertSame(['design', 'programming'], array_column($fresh->work_plan['operations'], 'department'));
        $this->assertSame(2, (int) $fresh->photography_sessions);

        // The kept department: new brief, hours, priority, and the assignee swapped.
        Http::assertSent(fn (Request $sent): bool => $sent->method() === 'PUT'
            && str_ends_with($sent->url(), '/task/task-design')
            && $sent['description'] === 'شعار وهوية كاملة'
            && $sent['time_estimate'] === 36_000_000
            && $sent['priority'] === 1
            && $sent['assignees'] === ['add' => [501], 'rem' => [499]]);
        // The added department gets a task in its list; the removed one is closed.
        Http::assertSent(fn (Request $sent): bool => $sent->method() === 'POST' && str_ends_with($sent->url(), '/list/list-programming/task'));
        Http::assertSent(fn (Request $sent): bool => $sent->method() === 'PUT' && str_ends_with($sent->url(), '/task/task-content') && ($sent['status'] ?? null) === 'complete');
        $this->assertSame('complete', ClickUpTask::query()->where('clickup_task_id', 'task-content')->value('status'));
    }

    public function test_extending_by_days_moves_clickup_and_tells_the_client(): void
    {
        $before = $this->request->client_due_at;

        $this->postJson("/api/admin/requests/{$this->request->id}/extend-schedule", ['days' => 2, 'reason' => 'ضغط موسم الأعياد'])
            ->assertOk();

        $this->assertTrue($this->request->fresh()->client_due_at->greaterThan($before));
        Http::assertSent(fn (Request $sent): bool => $sent->method() === 'PUT'
            && str_ends_with($sent->url(), '/task/task-design')
            && is_int($sent['due_date'] ?? null));
        Http::assertSent(fn (Request $sent): bool => str_contains($sent->url(), 'botclient-token/sendMessage')
            && str_contains((string) $sent['text'], 'يومي عمل')
            && str_contains((string) $sent['text'], 'موعد التسليم الجديد'));
    }
}
