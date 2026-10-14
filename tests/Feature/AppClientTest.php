<?php

namespace Tests\Feature;

use App\Actions\ClientAssistant;
use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\ClientReport;
use App\Models\DriveDelivery;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class AppClientTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.bot_token' => 'client-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $user = User::factory()->create(['is_admin' => false]);
        $this->client = Client::factory()->create([
            'user_id' => $user->id,
            'name' => 'Rami',
            'company_name' => 'Acme',
            'telegram_user_id' => '5150',
        ]);
        Sanctum::actingAs($user, ['app', 'role:client']);
    }

    public function test_home_summarises_only_this_clients_requests(): void
    {
        ServiceRequest::factory()->for($this->client)->create(['status' => RequestStatus::InProgress, 'amount_remaining' => 50]);
        ServiceRequest::factory()->for($this->client)->create(['status' => RequestStatus::QuotationSent]);
        ServiceRequest::factory()->for($this->client)->create(['status' => RequestStatus::Completed]);
        ServiceRequest::factory()->create(['status' => RequestStatus::InProgress]);

        $this->getJson('/api/app/client/home')
            ->assertOk()
            ->assertJsonPath('data.client_name', 'Rami')
            ->assertJsonPath('data.counts.active', 2)
            ->assertJsonPath('data.counts.awaiting_decision', 1)
            ->assertJsonPath('data.counts.completed', 1)
            ->assertJsonPath('data.amount_remaining', 50)
            ->assertJsonCount(3, 'data.latest_requests');
    }

    public function test_requests_filter_and_hide_internal_fields(): void
    {
        ServiceRequest::factory()->for($this->client)->create([
            'status' => RequestStatus::InProgress,
            'work_plan' => ['secret' => true],
        ]);
        ServiceRequest::factory()->for($this->client)->create(['status' => RequestStatus::Completed]);

        $active = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/app/client/requests?filter=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status_label', 'In progress')
            ->assertJsonPath('data.0.stage', 3)
            ->json('data.0');

        $this->assertArrayNotHasKey('work_plan', $active);
        $this->assertArrayNotHasKey('ai_analysis', $active);
        $this->assertArrayNotHasKey('clickup_error', $active);

        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/app/client/requests?filter=done')
            ->assertOk()
            ->assertJsonPath('data.0.status_label', 'مكتمل');
    }

    public function test_request_detail_lists_sent_files_and_quotations(): void
    {
        $request = ServiceRequest::factory()->for($this->client)->create(['status' => RequestStatus::InProgress]);
        DriveDelivery::query()->create(['request_id' => $request->id, 'drive_file_id' => 'f1', 'name' => 'logo.png', 'sent_at' => now()]);
        DriveDelivery::query()->create(['request_id' => $request->id, 'drive_file_id' => 'f2', 'name' => 'draft.png']);
        Quotation::query()->create(['request_id' => $request->id, 'version' => 1, 'amount' => 90, 'sent_at' => now()]);

        $this->getJson("/api/app/client/requests/{$request->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.files')
            ->assertJsonPath('data.files.0.name', 'logo.png')
            ->assertJsonPath('data.quotations.0.amount', 90);
    }

    public function test_another_clients_request_is_not_found(): void
    {
        $other = ServiceRequest::factory()->create();

        $this->getJson("/api/app/client/requests/{$other->id}")->assertNotFound();
    }

    public function test_client_approves_the_open_quotation(): void
    {
        $request = ServiceRequest::factory()->for($this->client)->create([
            'status' => RequestStatus::QuotationSent,
            'quotation_amount' => 120,
        ]);
        $quotation = Quotation::query()->create(['request_id' => $request->id, 'version' => 1, 'amount' => 120, 'sent_at' => now()]);

        $this->getJson('/api/app/client/quotations')
            ->assertOk()
            ->assertJsonPath('data.0.state', 'pending');

        $this->postJson("/api/app/client/quotations/{$quotation->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.state', 'approved');

        $this->assertSame(RequestStatus::AwaitingAdminApproval, $request->fresh()->status);
        $this->postJson("/api/app/client/quotations/{$quotation->id}/approve")->assertStatus(422);
    }

    public function test_client_rejects_with_a_reason_and_old_versions_are_closed(): void
    {
        $request = ServiceRequest::factory()->for($this->client)->create(['status' => RequestStatus::QuotationSent]);
        $old = Quotation::query()->create(['request_id' => $request->id, 'version' => 1, 'amount' => 150, 'sent_at' => now()->subDay()]);
        $latest = Quotation::query()->create(['request_id' => $request->id, 'version' => 2, 'amount' => 120, 'sent_at' => now()]);

        $this->postJson("/api/app/client/quotations/{$old->id}/reject", ['reason' => 'Too expensive'])->assertStatus(422);
        $this->postJson("/api/app/client/quotations/{$latest->id}/reject", ['reason' => ''])->assertStatus(422);

        $this->postJson("/api/app/client/quotations/{$latest->id}/reject", ['reason' => 'Too expensive'])
            ->assertOk()
            ->assertJsonPath('data.state', 'rejected');
        $this->assertSame(RequestStatus::QuotationRejected, $request->fresh()->status);
    }

    public function test_reports_are_published_only_and_pdf_streams(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('reports/r1.pdf', '%PDF-1.4 test');
        $published = ClientReport::query()->create([
            'client_id' => $this->client->id,
            'title' => 'September',
            'body' => '<p>Reach grew by 20%.</p>',
            'pdf_path' => 'reports/r1.pdf',
            'published_at' => now(),
        ]);
        ClientReport::query()->create(['client_id' => $this->client->id, 'title' => 'Draft', 'body' => 'Not yet']);

        $this->getJson('/api/app/client/reports')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.summary', 'Reach grew by 20%.')
            ->assertJsonPath('data.0.has_pdf', true);

        $this->get("/api/app/client/reports/{$published->id}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_profile_update_changes_client_and_user(): void
    {
        $this->patchJson('/api/app/client/profile', [
            'name' => 'Rami Haddad',
            'company_name' => 'Acme Studio',
            'company_activity' => 'Coffee',
            'email' => 'Rami@Acme.test',
            'locale' => 'en',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Rami Haddad')
            ->assertJsonPath('data.email', 'rami@acme.test')
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.channel', 'telegram');

        $this->assertSame('Rami Haddad', $this->client->user->fresh()->name);
        $this->patchJson('/api/app/client/profile', ['name' => 'R'])->assertStatus(422);
    }

    public function test_assistant_answers_and_points_to_a_request(): void
    {
        $request = ServiceRequest::factory()->for($this->client)->create(['number' => 'REQ-2026-000777']);
        $assistant = Mockery::mock(ClientAssistant::class);
        $assistant->shouldReceive('decide')
            ->once()
            ->withArgs(fn ($client, $message, $history, $locale, $step, $channel): bool => $client->is($this->client) && $channel === 'app' && $locale === 'ar')
            ->andReturn(['action' => 'open_request', 'ref' => '000777', 'answer' => 'طلبك قيد التنفيذ.', 'reason' => '']);
        $this->app->instance(ClientAssistant::class, $assistant);

        $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/app/client/assistant', ['message' => 'شو صار بطلبي؟'])
            ->assertOk()
            ->assertJsonPath('data.answer', 'طلبك قيد التنفيذ.')
            ->assertJsonPath('data.action', 'request')
            ->assertJsonPath('data.request_id', $request->id);
    }

    public function test_staff_without_a_client_row_is_refused(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['app', 'role:admin']);

        $this->getJson('/api/app/client/home')
            ->assertForbidden()
            ->assertJsonPath('code', 'no_client_account');
    }
}
