<?php

namespace Tests\Feature;

use App\Actions\EnqueueIntegrationEvent;
use App\Actions\IssueInvoice;
use App\Actions\ProvisionClickUpTasks;
use App\Enums\ClickUpTaskType;
use App\Enums\EmployeeProfession;
use App\Enums\IntegrationEventStatus;
use App\Enums\RequestStatus;
use App\Enums\WorkflowEventType;
use App\Enums\WorkType;
use App\Jobs\ClassifyWithGeminiJob;
use App\Jobs\DispatchIntegrationEventJob;
use App\Models\Client;
use App\Models\Employee;
use App\Models\IntegrationEvent;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\RequestStatusTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ServiceRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_create_request(): void
    {
        $this->postJson('/api/requests', [
            'title' => 'Booth',
            'description' => 'Need an exhibition booth.',
        ])->assertUnauthorized();
    }

    public function test_owner_can_create_and_list_own_request(): void
    {
        Http::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/requests', [
            'title' => 'Brand system',
            'description' => 'Full identity for a new hall.',
        ])->assertCreated()
            ->assertJsonPath('data.title', 'Brand system')
            ->assertJsonPath('data.status', 'submitted');

        $this->getJson('/api/requests')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_creating_request_enqueues_outbox_event(): void
    {
        Http::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/requests', [
            'title' => 'Exhibition booth',
            'description' => 'Need a 3D booth for the next fair.',
        ])->assertCreated();

        $this->assertDatabaseHas('integration_events', [
            'event_type' => WorkflowEventType::RequestSubmitted->value,
        ]);
    }

    public function test_n8n_callback_rejects_automatic_quotation(): void
    {
        Http::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $number = $this->postJson('/api/requests', [
            'title' => 'Paid ads',
            'description' => 'Launch campaign.',
        ])->json('data.number');

        $this->withHeaders(['X-N8N-Secret' => 'change-me'])
            ->postJson('/api/webhooks/n8n', [
                'event' => 'QUOTATION_READY',
                'request_number' => $number,
            ])->assertUnprocessable();
    }

    public function test_clickup_mapping_is_idempotent_and_keeps_submitted(): void
    {
        Http::fake();
        $client = Client::factory()->create();
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::Submitted,
        ]);

        $payload = [
            'request_number' => $request->number,
            'event_uuid' => '11111111-1111-1111-1111-111111111111',
            'request_uuid' => $request->uuid,
            'task_type' => ClickUpTaskType::Sales->value,
            'integration_key' => "{$request->uuid}:0:sales",
            'clickup_task_id' => 'CU-SALES-1',
            'clickup_url' => 'https://app.clickup.com/t/1',
        ];

        $this->withHeaders(['X-N8N-Secret' => 'change-me'])
            ->postJson('/api/integrations/clickup/mapping', $payload)
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $this->withHeaders(['X-N8N-Secret' => 'change-me'])
            ->postJson('/api/integrations/clickup/mapping', $payload)
            ->assertOk();

        $this->assertSame(1, $request->clickupTasks()->count());
    }

    public function test_in_progress_requires_all_execution_tasks_for_both(): void
    {
        Http::fake();
        $client = Client::factory()->create();
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::PaymentConfirmed,
            'work_type' => WorkType::Both,
        ]);

        $designKey = "{$request->uuid}:0:design";
        $this->withHeaders(['X-N8N-Secret' => 'change-me'])
            ->postJson('/api/integrations/clickup/mapping', [
                'request_number' => $request->number,
                'event_uuid' => '22222222-2222-2222-2222-222222222222',
                'request_uuid' => $request->uuid,
                'task_type' => 'design',
                'integration_key' => $designKey,
                'clickup_task_id' => 'CU-DESIGN',
            ])->assertOk()
            ->assertJsonPath('data.status', 'payment_confirmed');

        $this->withHeaders(['X-N8N-Secret' => 'change-me'])
            ->postJson('/api/integrations/clickup/mapping', [
                'request_number' => $request->number,
                'event_uuid' => '33333333-3333-3333-3333-333333333333',
                'request_uuid' => $request->uuid,
                'task_type' => 'content',
                'integration_key' => "{$request->uuid}:0:content",
                'clickup_task_id' => 'CU-CONTENT',
            ])->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
    }

    public function test_client_quotation_approve_is_idempotent(): void
    {
        Http::fake();
        $client = Client::factory()->create(['telegram_user_id' => 'tg-1']);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::QuotationSent,
        ]);
        Quotation::query()->create([
            'request_id' => $request->id,
            'version' => 1,
            'amount' => 100,
            'sent_at' => now(),
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$request->number}/approve", [
                'telegram_user_id' => 'tg-1',
            ])->assertOk()
            ->assertJsonPath('data.status', 'awaiting_payment');

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$request->number}/approve", [
                'telegram_user_id' => 'tg-1',
            ])->assertOk()
            ->assertJsonPath('data.status', 'awaiting_payment');
    }

    public function test_confirm_payment_queues_gemini_without_manual_status(): void
    {
        Http::fake();
        Bus::fake([ClassifyWithGeminiJob::class]);
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $request = ServiceRequest::factory()->create([
            'status' => RequestStatus::AwaitingPayment,
        ]);

        $this->patchJson("/api/admin/requests/{$request->id}", [
            'status' => 'payment_confirmed',
        ])->assertUnprocessable();

        $this->postJson("/api/admin/requests/{$request->id}/confirm-payment", [
            'payment_method' => 'cash',
        ])->assertOk()
            ->assertJsonPath('data.gemini_status', 'pending');
    }

    public function test_gemini_success_dispatches_payment_confirmed_outbox(): void
    {
        Http::fake($this->odooDocumentsHttpFake());
        $this->fakeOdooDocuments();
        $request = ServiceRequest::factory()->create([
            'status' => RequestStatus::AwaitingPayment,
            'gemini_status' => 'pending',
        ]);

        $mock = Mockery::mock(GeminiService::class);
        $mock->shouldReceive('classify')->once()->andReturn([
            'work_type' => WorkType::Design,
            'briefs' => [['type' => 'design', 'brief' => 'Design the booth.']],
        ]);
        $mock->shouldReceive('persistBriefs')->once();
        $this->app->instance(GeminiService::class, $mock);

        (new ClassifyWithGeminiJob($request->id))->handle(
            $mock,
            app(RequestStatusTransitionService::class),
            app(EnqueueIntegrationEvent::class),
            app(IssueInvoice::class),
            app(ProvisionClickUpTasks::class),
        );

        $this->assertDatabaseHas('integration_events', [
            'event_type' => WorkflowEventType::PaymentConfirmed->value,
            'request_number' => $request->number,
        ]);
        $this->assertSame(RequestStatus::PaymentConfirmed, $request->fresh()->status);
    }

    public function test_gemini_success_provisions_clickup_tasks_and_notifies_design_team(): void
    {
        Http::preventStrayRequests();
        $this->fakeOdooDocuments();
        config([
            'services.clickup.token' => 'pk_test',
            'services.clickup.list_id' => '901821114103',
            'services.clickup.lists.design' => '901821114103',
            'services.telegram.staff_bot_token' => 'staff-token',
            'services.telegram.bot_token' => 'client-token',
        ]);

        Http::fake(array_merge($this->odooDocumentsHttpFake(), [
            'https://api.clickup.com/*' => Http::response(['id' => 'cu-design-1'], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]));

        $client = Client::factory()->create(['telegram_user_id' => 'tg-design']);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::AwaitingPayment,
            'gemini_status' => 'pending',
            'quotation_amount' => 500,
        ]);

        Employee::factory()->create([
            'profession' => EmployeeProfession::Design,
            'telegram_user_id' => 'design-staff-1',
        ]);

        $gemini = app(GeminiService::class);
        $mock = Mockery::mock(GeminiService::class);
        $mock->shouldReceive('classify')->once()->andReturn([
            'work_type' => WorkType::Design,
            'briefs' => [['type' => 'design', 'brief' => 'Design the booth signage.']],
        ]);
        $mock->shouldReceive('persistBriefs')->once()->andReturnUsing(
            fn (ServiceRequest $serviceRequest, array $briefs): mixed => $gemini->persistBriefs($serviceRequest, $briefs),
        );
        $this->app->instance(GeminiService::class, $mock);

        (new ClassifyWithGeminiJob($request->id))->handle(
            $mock,
            app(RequestStatusTransitionService::class),
            app(EnqueueIntegrationEvent::class),
            app(IssueInvoice::class),
            app(ProvisionClickUpTasks::class),
        );

        $request->refresh();
        $this->assertSame(RequestStatus::InProgress, $request->status);
        $this->assertDatabaseHas('clickup_tasks', [
            'request_id' => $request->id,
            'clickup_task_id' => 'cu-design-1',
            'task_type' => 'design',
        ]);

        Http::assertSent(fn (Request $httpRequest): bool => str_contains($httpRequest->url(), 'api.clickup.com'));
        Http::assertSent(fn (Request $httpRequest): bool => str_contains($httpRequest->url(), 'api.telegram.org/botstaff-token'));
    }

    public function test_outbox_retry_reuses_same_event_uuid(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://n8n.test/*' => Http::response(['ok' => true], 200)]);
        config(['services.n8n.webhook_url' => 'https://n8n.test/webhook/hoc-events']);

        $request = ServiceRequest::factory()->create();
        $event = IntegrationEvent::query()->create([
            'event_uuid' => '44444444-4444-4444-4444-444444444444',
            'event_type' => WorkflowEventType::RequestSubmitted,
            'request_uuid' => $request->uuid,
            'request_number' => $request->number,
            'correlation_id' => '55555555-5555-5555-5555-555555555555',
            'aggregate_version' => 0,
            'payload' => ['title' => $request->title],
            'status' => IntegrationEventStatus::Failed,
        ]);

        app(EnqueueIntegrationEvent::class)->retry($event);
        (new DispatchIntegrationEventJob($event->id))->handle();

        Http::assertSent(fn (Request $httpRequest): bool => $httpRequest['event_uuid'] === '44444444-4444-4444-4444-444444444444');
    }
}
