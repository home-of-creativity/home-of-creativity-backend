<?php

namespace Tests\Feature;

use App\Actions\EnqueueIntegrationEvent;
use App\Actions\IssueInvoice;
use App\Actions\ProvisionClickUpTasks;
use App\Enums\ClickUpTaskType;
use App\Enums\EmployeeProfession;
use App\Enums\GeminiStatus;
use App\Enums\IntegrationEventStatus;
use App\Enums\RequestStatus;
use App\Enums\WorkflowEventType;
use App\Enums\WorkType;
use App\Jobs\ClassifyWithGeminiJob;
use App\Jobs\DispatchIntegrationEventJob;
use App\Models\Client;
use App\Models\Employee;
use App\Models\IntegrationEvent;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\RequestStatusTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class FullWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_workflow_from_telegram_submit_to_completion(): void
    {
        Http::preventStrayRequests();
        $this->fakeOdooDocuments();
        config([
            'services.telegram.staff_bot_token' => 'staff-token',
            'services.telegram.bot_token' => 'client-token',
            'services.clickup.token' => 'pk_test',
            'services.clickup.list_id' => '901821114100',
            'services.clickup.lists.sales' => '901821114100',
            'services.clickup.lists.design' => '901821114103',
            'services.n8n.webhook_url' => 'https://n8n.test/webhook/hoc-events',
            'services.n8n.webhook_secret' => 'change-me',
            'services.gemini.e2e_stub' => true,
        ]);

        Http::fake(array_merge($this->odooDocumentsHttpFake(), [
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['document' => ['file_id' => 'doc-1']]], 200),
            'https://api.clickup.com/*' => Http::response(['id' => 'cu-design-full'], 200),
            'https://n8n.test/*' => Http::response(['ok' => true], 200),
        ]));

        Employee::factory()->sales()->create(['telegram_user_id' => '6350001']);
        $designer = Employee::factory()->create([
            'telegram_user_id' => '6350002',
            'clickup_user_id' => 'cu-design-full',
            'profession' => EmployeeProfession::Design,
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson('/api/bot/telegram/link', [
                'telegram_user_id' => 'tg-full-flow',
                'name' => 'Full Flow Client',
                'phone' => '+963900000003',
                'company_name' => 'شركة المسار الكامل',
                'locale' => 'ar',
            ])->assertOk();

        $created = $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson('/api/bot/telegram/requests', [
                'telegram_user_id' => 'tg-full-flow',
                'title' => 'Full workflow booth',
                'description' => 'End-to-end automation coverage.',
            ])->assertCreated()
            ->json('data');

        $requestId = (int) $created['id'];
        $number = (string) $created['number'];
        $serviceRequest = ServiceRequest::query()->findOrFail($requestId);
        $this->assertSame('submitted', $created['status']);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/requests/{$requestId}/quotation", [
            'amount' => 1200,
            'notes' => 'Initial quotation',
        ])->assertOk()
            ->assertJsonPath('data.status', 'quotation_sent');

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$number}/reject", [
                'telegram_user_id' => 'tg-full-flow',
                'reason' => 'Too expensive',
            ])->assertOk()
            ->assertJsonPath('data.status', 'quotation_rejected');

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->postJson('/api/bot/staff/quotation', [
                'telegram_user_id' => '6350001',
                'request_number' => $number,
                'amount' => 950,
                'notes' => 'Revised offer',
            ])->assertOk()
            ->assertJsonPath('data.quotation_version', 2);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$number}/approve", [
                'telegram_user_id' => 'tg-full-flow',
            ])->assertOk()
            ->assertJsonPath('data.status', 'awaiting_payment');

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$number}/receipt", [
                'telegram_user_id' => 'tg-full-flow',
                'file_name' => 'receipt.pdf',
                'file_base64' => base64_encode('%PDF-1.4 fake'),
                'mime_type' => 'application/pdf',
            ])->assertOk()
            ->assertJsonPath('data.stored', true);

        $persistGemini = new GeminiService;
        $gemini = Mockery::mock(GeminiService::class);
        $classified = [
            'work_type' => WorkType::Design,
            'briefs' => [['type' => 'design', 'brief' => 'Design the booth signage.']],
        ];
        $gemini->shouldReceive('classificationFromWorkPlan')->once()->andReturn($classified);
        $gemini->shouldReceive('classify')->never();
        $gemini->shouldReceive('persistBriefs')->once()->andReturnUsing(
            fn (ServiceRequest $request, array $briefs): mixed => $persistGemini->persistBriefs($request, $briefs),
        );
        $this->app->instance(GeminiService::class, $gemini);

        $this->postJson("/api/admin/requests/{$requestId}/confirm-payment", [
            'payment_method' => 'cash',
            'amount' => 500,
        ])->assertOk();

        $serviceRequest->refresh();
        $this->assertSame(RequestStatus::InProgress, $serviceRequest->status);
        $this->assertSame(GeminiStatus::Success, $serviceRequest->gemini_status);
        $this->assertDatabaseHas('integration_events', [
            'request_number' => $number,
            'event_type' => WorkflowEventType::PaymentConfirmed->value,
        ]);

        $this->flushHeaders()
            ->withHeaders(['X-N8N-Secret' => 'change-me'])
            ->postJson('/api/integrations/clickup/mapping', [
                'request_number' => $number,
                'request_uuid' => $serviceRequest->uuid,
                'event_uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
                'task_type' => ClickUpTaskType::Design->value,
                'integration_key' => "{$serviceRequest->uuid}:0:design",
                'clickup_task_id' => 'cu-design-full',
                'clickup_user_id' => 'cu-design-full',
            ])->assertOk();

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->postJson('/api/bot/staff/deliver', [
                'telegram_user_id' => '6350002',
                'request_number' => $number,
                'notes' => 'First delivery',
            ])->assertOk()
            ->assertJsonPath('data.status', 'ready_for_review');

        $this->assertDatabaseHas('integration_events', [
            'request_uuid' => $serviceRequest->uuid,
            'event_type' => WorkflowEventType::DeliveryReady->value,
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$number}/revision", [
                'telegram_user_id' => 'tg-full-flow',
                'reason' => 'Adjust lighting',
            ])->assertOk()
            ->assertJsonPath('data.status', 'revision_requested');

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->postJson('/api/bot/staff/deliver', [
                'telegram_user_id' => '6350002',
                'request_number' => $number,
                'notes' => 'Revised delivery',
            ])->assertOk()
            ->assertJsonPath('data.status', 'ready_for_review');

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->postJson('/api/bot/staff/complete', [
                'telegram_user_id' => '6350001',
                'request_number' => $number,
            ])->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('integration_events', [
            'request_uuid' => $serviceRequest->uuid,
            'event_type' => WorkflowEventType::ProjectCompleted->value,
        ]);

        Http::assertSent(fn (Request $httpRequest): bool => str_contains($httpRequest->url(), 'n8n.test'));
        $this->assertSame(RequestStatus::Completed, $serviceRequest->fresh()->status);
    }

    public function test_tasks_ready_inbound_applies_clickup_mapping(): void
    {
        Http::fake();
        $request = ServiceRequest::factory()->create([
            'status' => RequestStatus::PaymentConfirmed,
            'work_type' => WorkType::Design,
        ]);

        $payload = [
            'request_number' => $request->number,
            'event_uuid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'request_uuid' => $request->uuid,
            'task_type' => ClickUpTaskType::Design->value,
            'integration_key' => "{$request->uuid}:0:design",
            'clickup_task_id' => 'CU-TASKS-READY',
        ];

        $this->withHeaders(['X-N8N-Secret' => 'change-me'])
            ->postJson('/api/webhooks/n8n', [
                'event' => WorkflowEventType::TasksReady->value,
                'request_number' => $request->number,
                'payload' => $payload,
            ])->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $this->assertDatabaseHas('clickup_tasks', [
            'request_id' => $request->id,
            'clickup_task_id' => 'CU-TASKS-READY',
            'task_type' => 'design',
        ]);
    }

    public function test_gemini_failure_and_admin_retry_succeeds(): void
    {
        $this->fakeOdooDocuments();
        Http::fake(array_merge($this->odooDocumentsHttpFake(), [
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
            'https://api.clickup.com/*' => Http::response(['id' => 'cu-retry'], 200),
        ]));

        $request = ServiceRequest::factory()->create([
            'status' => RequestStatus::AwaitingPayment,
            'gemini_status' => GeminiStatus::Pending,
        ]);

        $failing = Mockery::mock(GeminiService::class);
        $failing->shouldReceive('classify')->once()->andThrow(new \RuntimeException('Gemini unavailable'));
        $this->app->instance(GeminiService::class, $failing);

        try {
            (new ClassifyWithGeminiJob($request->id))->handle(
                $failing,
                app(RequestStatusTransitionService::class),
                app(EnqueueIntegrationEvent::class),
                app(IssueInvoice::class),
                app(ProvisionClickUpTasks::class),
            );
        } catch (\RuntimeException) {
            // Expected in sync queue tests.
        }

        $request->refresh();
        $this->assertSame(GeminiStatus::Failed, $request->gemini_status);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $persistGemini = new GeminiService;
        $success = Mockery::mock(GeminiService::class);
        $success->shouldReceive('classify')->once()->andReturn([
            'work_type' => WorkType::Design,
            'briefs' => [['type' => 'design', 'brief' => 'Retry brief.']],
        ]);
        $success->shouldReceive('persistBriefs')->once()->andReturnUsing(
            fn (ServiceRequest $serviceRequest, array $briefs): mixed => $persistGemini->persistBriefs($serviceRequest, $briefs),
        );
        $this->app->instance(GeminiService::class, $success);

        $this->postJson("/api/admin/requests/{$request->id}/retry-gemini")
            ->assertOk()
            ->assertJsonPath('data.gemini_status', 'success');

        $request->refresh();
        $this->assertSame(RequestStatus::PaymentConfirmed, $request->status);
    }

    public function test_outbox_retry_reuses_same_event_uuid(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://n8n.test/*' => Http::response(['ok' => true], 200)]);
        config(['services.n8n.webhook_url' => 'https://n8n.test/webhook/hoc-events']);

        $request = ServiceRequest::factory()->create();
        $event = IntegrationEvent::query()->create([
            'event_uuid' => '77777777-7777-7777-7777-777777777777',
            'event_type' => WorkflowEventType::DeliveryReady,
            'request_uuid' => $request->uuid,
            'request_number' => $request->number,
            'correlation_id' => '88888888-8888-8888-8888-888888888888',
            'aggregate_version' => 0,
            'payload' => ['notes' => 'Delivery ready'],
            'status' => IntegrationEventStatus::Failed,
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/integration-events/{$event->id}/retry")
            ->assertOk();

        (new DispatchIntegrationEventJob($event->id))->handle();

        Http::assertSent(fn (Request $httpRequest): bool => $httpRequest['event_uuid'] === '77777777-7777-7777-7777-777777777777');
    }

    public function test_admin_can_sync_odoo_partners_during_workflow(): void
    {
        Http::preventStrayRequests();
        config([
            'services.odoo.enabled' => true,
            'services.odoo.url' => 'https://odoo.test',
            'services.odoo.db' => 'hoc',
            'services.odoo.username' => 'admin',
            'services.odoo.api_key' => 'secret-key',
        ]);
        Http::fake([
            'https://odoo.test/jsonrpc' => Http::sequence()
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => [[
                    'id' => 77,
                    'name' => 'Workflow Odoo Client',
                    'email' => 'workflow@hoc.test',
                    'phone' => '+963222',
                    'mobile' => false,
                ]]], 200),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/odoo/sync-partners')
            ->assertOk()
            ->assertJsonPath('data.created', 1);

        $this->assertDatabaseHas('clients', [
            'email' => 'workflow@hoc.test',
            'odoo_partner_id' => '77',
        ]);

        $client = Client::query()->where('email', 'workflow@hoc.test')->firstOrFail();
        $this->assertSame('Workflow Odoo Client', $client->name);
    }
}
