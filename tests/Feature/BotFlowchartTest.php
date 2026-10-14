<?php

namespace Tests\Feature;

use App\Enums\ClickUpTaskType;
use App\Enums\EmployeeProfession;
use App\Enums\RequestStatus;
use App\Enums\WorkType;
use App\Models\ClickUpTask;
use App\Models\Client;
use App\Models\Employee;
use App\Models\PricingCategory;
use App\Models\PricingPackage;
use App\Models\PricingSubcategory;
use App\Models\ServiceRequest;
use App\Models\SupportMessage;
use App\Models\User;
use App\Services\DevBeat;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Concerns\FakesOdooDocuments;
use Tests\TestCase;

class BotFlowchartTest extends TestCase
{
    use FakesOdooDocuments;
    use RefreshDatabase;

    public function test_all_bots_reject_missing_or_wrong_secrets(): void
    {
        $this->postJson('/api/bot/telegram/link', [
            'telegram_user_id' => '1',
            'name' => 'A',
        ])->assertUnauthorized();

        $this->withHeaders(['X-Webhook-Secret' => 'wrong'])
            ->postJson('/api/bot/staff/join', [
                'telegram_user_id' => '1',
                'name' => 'A',
            ])->assertUnauthorized();

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->getJson('/api/bot/admin/me?telegram_user_id=1')
            ->assertUnauthorized();
    }

    public function test_client_profile_staircase_saves_phone_and_company(): void
    {
        $this->clientBot()->postJson('/api/bot/telegram/link', [
            'telegram_user_id' => 'tg-stairs',
            'name' => 'Nour',
            'locale' => 'ar',
        ])->assertOk()
            ->assertJsonPath('data.profile_complete', false)
            ->assertJsonPath('data.missing_fields.0', 'phone');

        $this->clientBot()->postJson('/api/bot/telegram/profile', [
            'telegram_user_id' => 'tg-stairs',
            'phone' => '+963911111111',
        ])->assertOk()
            ->assertJsonPath('data.missing_fields.0', 'company_name');

        $this->clientBot()->postJson('/api/bot/telegram/profile', [
            'telegram_user_id' => 'tg-stairs',
            'company_name' => 'شركة نون',
        ])->assertOk()
            ->assertJsonPath('data.profile_complete', true)
            ->assertJsonPath('data.company_name', 'نون')
            ->assertJsonPath('data.missing_fields.0', 'email');

        $this->clientBot()->postJson('/api/bot/telegram/profile', [
            'telegram_user_id' => 'tg-stairs',
            'phone' => '🆕 طلب جديد',
        ])->assertUnprocessable();

        $this->clientBot()->postJson('/api/bot/telegram/profile', [
            'telegram_user_id' => 'tg-stairs',
            'email' => 'nour-at-mail',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'أرسل بريداً إلكترونياً صالحاً، أو اضغط «لا يوجد بريد».');

        $this->clientBot()->postJson('/api/bot/telegram/profile', [
            'telegram_user_id' => 'tg-stairs',
            'email' => 'لا يوجد',
        ])->assertOk()
            ->assertJsonPath('data.missing_fields.0', 'company_activity');

        $this->clientBot()->postJson('/api/bot/telegram/profile', [
            'telegram_user_id' => 'tg-stairs',
            'company_activity' => '0999111222',
        ])->assertUnprocessable();

        $this->clientBot()->postJson('/api/bot/telegram/profile', [
            'telegram_user_id' => 'tg-stairs',
            'company_activity' => 'مطعم',
        ])->assertOk()
            ->assertJsonPath('data.missing_fields', []);

        $this->clientBot()->getJson('/api/bot/telegram/me?telegram_user_id=tg-stairs')
            ->assertOk()
            ->assertJsonPath('data.profile_complete', true)
            ->assertJsonPath('data.phone', '+963911111111')
            ->assertJsonPath('data.company_name', 'نون')
            ->assertJsonPath('data.company_activity', 'مطعم');

        $client = Client::query()->where('telegram_user_id', 'tg-stairs')->firstOrFail();
        $this->assertSame('نون', $client->company_name);
        $this->assertNull($client->email);
        $this->assertNotNull($client->email_skipped_at);
        $this->assertTrue($client->profileFinished());
    }

    public function test_existing_client_is_asked_only_the_new_questions_and_the_skip_button_works(): void
    {
        $client = Client::factory()->create([
            'telegram_user_id' => 'tg-legacy',
            'name' => 'Rami',
            'phone' => '+963933333333',
            'company_name' => 'مخبز رامي',
            'email' => null,
        ]);

        $this->clientBot()->getJson('/api/bot/telegram/me?telegram_user_id=tg-legacy')
            ->assertOk()
            ->assertJsonPath('data.profile_complete', true)
            ->assertJsonPath('data.missing_fields', ['email', 'company_activity']);

        $this->clientBot()->postJson('/api/bot/telegram/profile', [
            'telegram_user_id' => 'tg-legacy',
            'email_skipped' => true,
        ])->assertOk()
            ->assertJsonPath('data.missing_fields', ['company_activity']);

        $this->clientBot()->postJson('/api/bot/telegram/profile', [
            'telegram_user_id' => 'tg-legacy',
            'company_activity' => 'مخابز',
        ])->assertOk()
            ->assertJsonPath('data.missing_fields', []);

        $this->assertTrue($client->fresh()->profileFinished());
    }

    public function test_unknown_staff_and_non_admin_are_rejected(): void
    {
        config(['services.telegram.admin_telegram_ids' => ['8260054672']]);

        $this->staffBot()->getJson('/api/bot/staff/me?telegram_user_id=999')
            ->assertNotFound();

        $this->adminBot()->getJson('/api/bot/admin/me?telegram_user_id=1')
            ->assertForbidden();

        $this->adminBot()->getJson('/api/bot/admin/me?telegram_user_id=8260054672')
            ->assertOk()
            ->assertJsonPath('data.is_admin', true);
    }

    public function test_staff_join_stays_pending_until_dashboard_approves(): void
    {
        $this->staffBot()->postJson('/api/bot/staff/join', [
            'telegram_user_id' => 'staff-join-1',
            'name' => 'Ammar',
            'telegram_username' => 'ammar',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.code', 'EMP-0001');

        $this->staffBot()->getJson('/api/bot/staff/new-requests?telegram_user_id=staff-join-1')
            ->assertNotFound();

        Sanctum::actingAs($this->adminUser());
        $employee = Employee::query()->where('telegram_user_id', 'staff-join-1')->firstOrFail();
        $this->postJson("/api/admin/employees/{$employee->id}/approve", [
            'profession' => 'sales',
        ])->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->staffBot()->getJson('/api/bot/staff/me?telegram_user_id=staff-join-1')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.is_active', true);
    }

    public function test_client_catalog_manual_edit_cancel_and_support(): void
    {
        $this->fakeBotIntegrations();
        $package = $this->seedPackage();
        $this->completeProfile('tg-catalog-flow');

        $root = $this->clientBot()->getJson('/api/bot/telegram/catalog?telegram_user_id=tg-catalog-flow')
            ->assertOk()
            ->json('data');
        $this->assertSame('categories', $root['kind']);
        $this->assertArrayNotHasKey('price', $root['items'][0]);

        $categoryId = $package->subcategory->category_id;
        $children = $this->clientBot()->getJson(
            '/api/bot/telegram/catalog?telegram_user_id=tg-catalog-flow&category_id='.$categoryId,
        )->assertOk()->json('data');
        $this->assertNotEmpty($children['items']);

        $periods = $this->clientBot()->getJson(
            '/api/bot/telegram/catalog?telegram_user_id=tg-catalog-flow&package_id='.$package->id,
        )->assertOk()->json('data');
        $this->assertSame('periods', $periods['kind']);
        $this->assertContains('monthly', $periods['items'][0]['periods'] ?? []);

        $quoted = $this->clientBot()->postJson('/api/bot/telegram/catalog/requests', [
            'telegram_user_id' => 'tg-catalog-flow',
            'package_id' => $package->id,
            'billing_period' => 'monthly',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'quotation_sent')
            ->assertJsonPath('data.status_label', 'عرض سعر مرسل')
            ->assertJsonPath('data.quotation_delivered', true)
            ->json('data');

        $manual = $this->clientBot()->postJson('/api/bot/telegram/requests', [
            'telegram_user_id' => 'tg-catalog-flow',
            'title' => 'طلب يدوي',
            'description' => 'وصف مختصر.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'submitted')
            ->json('data');

        $this->clientBot()->patchJson('/api/bot/telegram/requests/'.$manual['number'], [
            'telegram_user_id' => 'tg-catalog-flow',
            'title' => 'طلب يدوي محدّث',
        ])->assertOk()
            ->assertJsonPath('data.title', 'طلب يدوي محدّث');

        $this->clientBot()->postJson('/api/bot/telegram/requests/'.$manual['number'].'/cancel', [
            'telegram_user_id' => 'tg-catalog-flow',
        ])->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->clientBot()->postJson('/api/bot/telegram/support', [
            'telegram_user_id' => 'tg-catalog-flow',
            'message' => 'متى يبدأ العمل؟',
            'request_number' => $quoted['number'],
        ])->assertOk()
            ->assertJsonPath('data.stored', true);

        $this->assertSame(1, SupportMessage::query()->count());

        $list = $this->clientBot()->getJson('/api/bot/telegram/requests?telegram_user_id=tg-catalog-flow')
            ->assertOk()
            ->json('data');
        $this->assertGreaterThanOrEqual(2, count($list));
        $this->assertNotSame('', (string) ($list[0]['status_label'] ?? ''));
        $this->assertNotSame($list[0]['status'], $list[0]['status_label']);
    }

    public function test_flowchart_from_catalog_quote_through_staff_and_client_complete(): void
    {
        $this->fakeBotIntegrations();
        $package = $this->seedPackage();
        $this->completeProfile('tg-flow');

        Employee::factory()->sales()->create(['telegram_user_id' => 'sales-flow']);
        $designer = Employee::factory()->create([
            'telegram_user_id' => 'design-flow',
            'profession' => EmployeeProfession::Design,
            'clickup_user_id' => 'cu-design-flow',
        ]);

        $created = $this->clientBot()->postJson('/api/bot/telegram/catalog/requests', [
            'telegram_user_id' => 'tg-flow',
            'package_id' => $package->id,
            'billing_period' => 'monthly',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'quotation_sent')
            ->assertJsonPath('data.status_label', 'عرض سعر مرسل')
            ->assertJsonPath('data.quotation_delivered', true)
            ->json('data');

        $number = (string) $created['number'];
        $requestId = (int) $created['id'];

        $this->staffBot()->getJson('/api/bot/staff/new-requests?telegram_user_id=sales-flow')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->staffBot()->getJson('/api/bot/staff/quotable-requests?telegram_user_id=sales-flow')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->clientBot()->postJson("/api/bot/telegram/requests/{$number}/reject", [
            'telegram_user_id' => 'tg-flow',
            'reason' => 'السعر غالي',
        ])->assertOk()
            ->assertJsonPath('data.status', 'quotation_rejected');

        $this->staffBot()->getJson('/api/bot/staff/quotable-requests?telegram_user_id=sales-flow')
            ->assertOk()
            ->assertJsonPath('data.0.number', $number);

        $this->staffBot()->postJson('/api/bot/staff/quotation', [
            'telegram_user_id' => 'sales-flow',
            'request_number' => $number,
            'amount' => 350,
            'notes' => 'عرض معدّل',
        ])->assertOk()
            ->assertJsonPath('data.quotation_version', 2);

        $this->clientBot()->postJson("/api/bot/telegram/requests/{$number}/approve", [
            'telegram_user_id' => 'tg-flow',
        ])->assertOk()
            ->assertJsonPath('data.status', 'awaiting_payment');

        $this->clientBot()->postJson("/api/bot/telegram/requests/{$number}/receipt", [
            'telegram_user_id' => 'tg-flow',
            'file_name' => 'receipt.pdf',
            'file_base64' => base64_encode('%PDF-1.4 fake'),
            'mime_type' => 'application/pdf',
        ])->assertOk()
            ->assertJsonPath('data.stored', true);

        $persistGemini = new GeminiService;
        $gemini = Mockery::mock(GeminiService::class);
        $classified = [
            'work_type' => WorkType::Design,
            'briefs' => [['type' => 'design', 'brief' => 'Design the identity.']],
        ];
        $gemini->shouldReceive('classificationFromWorkPlan')->never();
        $gemini->shouldReceive('classify')->once()->andReturn($classified);
        $gemini->shouldReceive('persistBriefs')->once()->andReturnUsing(
            fn (ServiceRequest $request, array $briefs): mixed => $persistGemini->persistBriefs($request, $briefs),
        );
        $this->app->instance(GeminiService::class, $gemini);

        Sanctum::actingAs($this->adminUser());
        $this->postJson("/api/admin/requests/{$requestId}/confirm-payment", [
            'payment_method' => 'cash',
            'amount' => 500,
        ])->assertOk();

        $serviceRequest = ServiceRequest::query()->findOrFail($requestId);
        $this->assertSame(RequestStatus::PaymentConfirmed, $serviceRequest->status);

        $this->staffBot()->getJson('/api/bot/staff/progressable-requests?telegram_user_id=sales-flow')
            ->assertOk()
            ->assertJsonPath('data.0.number', $number);

        $this->staffBot()->postJson('/api/bot/staff/in-progress', [
            'telegram_user_id' => 'sales-flow',
            'request_number' => $number,
        ])->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        ClickUpTask::query()->create([
            'request_id' => $requestId,
            'task_type' => ClickUpTaskType::Design,
            'employee_id' => $designer->id,
            'integration_key' => $serviceRequest->uuid.':0:design',
            'clickup_task_id' => 'cu-design-flow',
            'clickup_url' => 'https://app.clickup.com/t/cu-design-flow',
        ]);

        $this->staffBot()->getJson('/api/bot/staff/tasks?telegram_user_id=design-flow')
            ->assertOk()
            ->assertJsonPath('data.0.number', $number);

        $this->staffBot()->postJson('/api/bot/staff/deliver', [
            'telegram_user_id' => 'design-flow',
            'request_number' => $number,
            'notes' => 'التسليم الأول',
        ])->assertOk()
            ->assertJsonPath('data.status', 'ready_for_review');

        $this->clientBot()->postJson("/api/bot/telegram/requests/{$number}/revision", [
            'telegram_user_id' => 'tg-flow',
            'reason' => 'تعديل الشعار',
        ])->assertOk()
            ->assertJsonPath('data.status', 'revision_requested');

        $this->staffBot()->postJson('/api/bot/staff/deliver', [
            'telegram_user_id' => 'design-flow',
            'request_number' => $number,
            'notes' => 'التسليم بعد التعديل',
        ])->assertOk()
            ->assertJsonPath('data.status', 'ready_for_review');

        $this->staffBot()->getJson('/api/bot/staff/completable-requests?telegram_user_id=sales-flow')
            ->assertOk()
            ->assertJsonPath('data.0.number', $number);

        $this->clientBot()->postJson("/api/bot/telegram/requests/{$number}/complete", [
            'telegram_user_id' => 'tg-flow',
        ])->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_bot_down_flow_reaches_completion_from_the_dashboard(): void
    {
        $this->fakeBotIntegrations();
        Http::preventStrayRequests();
        $this->mock(DevBeat::class, fn ($mock) => $mock->shouldReceive('ageSeconds')->andReturn(null));
        $designer = Employee::factory()->create([
            'telegram_user_id' => 'design-down',
            'profession' => EmployeeProfession::Design,
            'clickup_user_id' => 'cu-design-down',
        ]);
        $client = Client::factory()->create([
            'telegram_user_id' => '880011',
            'company_name' => 'مكتب الهاتف',
            'company_activity' => 'خدمات',
        ]);
        $request = ServiceRequest::factory()->for($client)->create(['status' => RequestStatus::Submitted]);
        Sanctum::actingAs($this->adminUser());

        $this->postJson("/api/admin/requests/{$request->number}/quotation", [
            'lines' => [['title' => 'بطاقة أعمال', 'amount' => 100, 'units' => 1]],
        ])->assertOk()
            ->assertJsonPath('data.status', 'quotation_sent');

        $this->getJson("/api/admin/requests/{$request->number}")
            ->assertOk()
            ->assertJsonPath('data.client_bot_reachable', false);

        $this->postJson("/api/admin/requests/{$request->number}/client-decision", ['decision' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_payment');

        $this->postJson("/api/admin/requests/{$request->number}/confirm-payment", [
            'payment_method' => 'cash',
            'amount' => 100,
        ])->assertOk();

        $this->patchJson("/api/admin/requests/{$request->number}", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        ClickUpTask::query()->create([
            'request_id' => $request->id,
            'task_type' => ClickUpTaskType::Design,
            'employee_id' => $designer->id,
            'integration_key' => $request->uuid.':down:design',
            'clickup_task_id' => 'cu-design-down',
        ]);

        $this->staffBot()->postJson('/api/bot/staff/deliver', [
            'telegram_user_id' => 'design-down',
            'request_number' => $request->number,
            'notes' => 'التسليم الأول',
        ])->assertOk()
            ->assertJsonPath('data.status', 'ready_for_review');

        $this->postJson("/api/admin/requests/{$request->number}/client-decision", [
            'decision' => 'revision',
            'reason' => 'تكبير الشعار',
        ])->assertOk()
            ->assertJsonPath('data.status', 'revision_requested');

        $this->staffBot()->postJson('/api/bot/staff/deliver', [
            'telegram_user_id' => 'design-down',
            'request_number' => $request->number,
            'notes' => 'التسليم بعد التعديل',
        ])->assertOk()
            ->assertJsonPath('data.status', 'ready_for_review');

        $this->postJson("/api/admin/requests/{$request->number}/client-decision", ['decision' => 'complete'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonCount(3, 'data.manual_decisions');
    }

    public function test_admin_bot_lists_departments_invites_guest_and_assigns_members(): void
    {
        config([
            'services.telegram.admin_telegram_ids' => ['8260054672'],
            'services.clickup.token' => 'pk_test',
            'services.clickup.team_id' => 'team-1',
            'services.clickup.lists.sales' => 'list-sales',
            'services.clickup.lists.design' => 'list-design',
            'services.clickup.lists.content' => 'list-content',
            'services.clickup.lists.programming' => 'list-code',
            'services.clickup.lists.photography' => 'list-photo',
            'services.clickup.lists.revision' => '',
        ]);
        Http::fake([
            'https://api.clickup.com/api/v2/team/team-1/guest' => Http::response(['guest' => ['id' => 'g1']], 200),
            'https://api.clickup.com/api/v2/list/list-design/member' => Http::response([
                'members' => [
                    ['id' => 77, 'username' => 'Designer', 'email' => 'd@example.com'],
                ],
            ], 200),
            'https://api.clickup.com/api/v2/list/list-design/task*' => Http::response([
                'tasks' => [[
                    'id' => 'tsk-1',
                    'name' => 'Identity',
                    'status' => ['status' => 'open'],
                    'url' => 'https://app.clickup.com/t/tsk-1',
                    'assignees' => [],
                ]],
            ], 200),
            'https://api.clickup.com/api/v2/task/tsk-1' => Http::response(['id' => 'tsk-1'], 200),
        ]);

        $this->adminBot()->getJson('/api/bot/admin/departments?telegram_user_id=8260054672')
            ->assertOk()
            ->assertJsonCount(5, 'data');

        $this->adminBot()->getJson('/api/bot/admin/tasks?telegram_user_id=8260054672')
            ->assertStatus(422);

        $this->adminBot()->getJson('/api/bot/admin/tasks?telegram_user_id=8260054672&department=design')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'tsk-1');

        $this->adminBot()->postJson('/api/bot/admin/guests', [
            'telegram_user_id' => '8260054672',
            'name' => 'Guest',
            'departments' => ['design'],
        ])->assertUnprocessable();

        $this->adminBot()->postJson('/api/bot/admin/guests', [
            'telegram_user_id' => '8260054672',
            'email' => 'guest@example.com',
            'name' => 'Guest Designer',
            'departments' => ['design'],
            'profession' => 'design',
        ])->assertCreated()
            ->assertJsonPath('data.invite.ok', true);

        $this->adminBot()->postJson('/api/bot/admin/assign', [
            'telegram_user_id' => '8260054672',
            'department' => 'design',
            'task_id' => 'tsk-1',
            'assignee_id' => '999',
        ])->assertUnprocessable();

        $this->adminBot()->postJson('/api/bot/admin/assign', [
            'telegram_user_id' => '8260054672',
            'department' => 'design',
            'task_id' => 'tsk-1',
            'assignee_id' => '77',
        ])->assertOk()
            ->assertJsonPath('data.assigned', true);
    }

    public function test_staff_delete_restores_telegram_client_and_keeps_requests(): void
    {
        $this->fakeBotIntegrations();
        $this->completeProfile('tg-restore');
        $client = Client::query()->where('telegram_user_id', 'tg-restore')->firstOrFail();
        $serviceRequest = ServiceRequest::factory()->for($client)->create([
            'title' => 'طلب محفوظ بعد الحذف',
        ]);

        Sanctum::actingAs($this->adminUser());
        $this->deleteJson("/api/admin/clients/{$client->id}")->assertOk();
        $this->assertSoftDeleted($client);
        $this->assertDatabaseHas('requests', [
            'id' => $serviceRequest->id,
            'client_id' => $client->id,
        ]);

        $listed = $this->getJson('/api/admin/clients')->assertOk()->json('data');
        $this->assertFalse(collect($listed)->contains(fn ($row) => (int) ($row['id'] ?? 0) === $client->id));

        $this->clientBot()->getJson('/api/bot/telegram/me?telegram_user_id=tg-restore')
            ->assertOk()
            ->assertJsonPath('data.id', $client->id)
            ->assertJsonPath('data.profile_complete', true)
            ->assertJsonPath('data.phone', '+963900000001')
            ->assertJsonPath('data.company_name', 'restore');

        $this->assertNull($client->fresh()->deleted_at);
        $this->assertSame(1, Client::query()->withTrashed()->where('telegram_user_id', 'tg-restore')->count());

        $items = $this->clientBot()->getJson('/api/bot/telegram/requests?telegram_user_id=tg-restore')
            ->assertOk()
            ->json('data');
        $this->assertTrue(collect($items)->contains(
            fn ($row) => ($row['number'] ?? null) === $serviceRequest->number,
        ));

        $this->deleteJson("/api/admin/clients/{$client->id}")->assertOk();
        $this->assertSoftDeleted($client);

        $this->clientBot()->postJson('/api/bot/telegram/link', [
            'telegram_user_id' => 'tg-restore',
            'name' => 'Client tg-restore',
            'locale' => 'ar',
        ])->assertOk()
            ->assertJsonPath('data.id', $client->id)
            ->assertJsonPath('data.profile_complete', true);

        $this->assertSame(1, Client::query()->withTrashed()->where('telegram_user_id', 'tg-restore')->count());
    }

    public function test_sales_cannot_complete_before_ready_for_review(): void
    {
        Employee::factory()->sales()->create(['telegram_user_id' => 'sales-early']);
        $request = ServiceRequest::factory()->create([
            'status' => RequestStatus::InProgress,
        ]);

        $this->staffBot()->postJson('/api/bot/staff/complete', [
            'telegram_user_id' => 'sales-early',
            'request_number' => $request->number,
        ])->assertStatus(422);
    }

    private function clientBot()
    {
        return $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot', 'Accept' => 'application/json']);
    }

    private function staffBot()
    {
        return $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff', 'Accept' => 'application/json']);
    }

    private function adminBot()
    {
        return $this->withHeaders(['X-Webhook-Secret' => 'change-me-admin', 'Accept' => 'application/json']);
    }

    private function adminUser(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    private function completeProfile(string $telegramId): void
    {
        $this->clientBot()->postJson('/api/bot/telegram/link', [
            'telegram_user_id' => $telegramId,
            'name' => 'Client '.$telegramId,
            'locale' => 'ar',
        ])->assertOk();

        $this->clientBot()->postJson('/api/bot/telegram/profile', [
            'telegram_user_id' => $telegramId,
            'phone' => '+963900000001',
        ])->assertOk();

        $this->clientBot()->postJson('/api/bot/telegram/profile', [
            'telegram_user_id' => $telegramId,
            'company_name' => 'شركة '.str_replace('tg-', '', $telegramId),
        ])->assertOk()
            ->assertJsonPath('data.profile_complete', true);
    }

    private function seedPackage(): PricingPackage
    {
        $category = PricingCategory::query()->create([
            'slug' => 'retainers-flow',
            'name_en' => 'Retainers',
            'name_ar' => 'اشتراكات',
            'sort_order' => 1,
            'is_published' => true,
            'requires_full_payment' => false,
            'allows_renewal' => true,
        ]);
        $sub = PricingSubcategory::query()->create([
            'category_id' => $category->id,
            'slug' => 'monthly-flow',
            'name_en' => 'Monthly',
            'name_ar' => 'شهري',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        return PricingPackage::query()->create([
            'subcategory_id' => $sub->id,
            'slug' => 'startup-flow',
            'name_en' => 'Startup',
            'name_ar' => 'Startup',
            'subtitle_en' => 'Small',
            'subtitle_ar' => 'صغير',
            'prices' => ['monthly' => 399, 'quarterly' => 1137],
            'features' => [],
            'sort_order' => 1,
            'is_published' => true,
        ]);
    }

    private function fakeBotIntegrations(): void
    {
        $this->fakeOdooDocuments();
        config([
            'services.telegram.bot_token' => 'client-token',
            'services.telegram.staff_bot_token' => 'staff-token',
            'services.gemini.e2e_stub' => true,
            'services.clickup.token' => '',
            'services.n8n.webhook_url' => '',
        ]);
        Http::fake(array_merge($this->odooDocumentsHttpFake(), [
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['document' => ['file_id' => 'doc-1']]], 200),
        ]));
    }
}
