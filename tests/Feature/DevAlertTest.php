<?php

namespace Tests\Feature;

use App\Enums\IntegrationEventStatus;
use App\Enums\RequestStatus;
use App\Enums\WorkflowEventType;
use App\Models\IntegrationEvent;
use App\Models\ServiceRequest;
use App\Services\DevBeat;
use App\Services\GoogleDriveClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DevAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_sentry_webhook_rejects_a_missing_secret(): void
    {
        $this->postJson('/api/integrations/sentry', [
            'data' => ['issue' => ['title' => 'Boom']],
        ])->assertUnauthorized();
    }

    public function test_sentry_webhook_alerts_developers(): void
    {
        config(['services.sentry.webhook_secret' => 'sentry-secret']);
        Http::fake();

        $this->withHeaders(['X-Webhook-Secret' => 'sentry-secret'])
            ->postJson('/api/integrations/sentry', [
                'data' => ['issue' => ['title' => 'Boom', 'web_url' => 'https://sentry.example/issues/1']],
            ])
            ->assertOk();
    }

    public function test_health_watch_alerts_once_when_the_api_is_down(): void
    {
        config([
            'services.telegram.dev_bot_token' => '',
            'services.telegram.dev_chat_id' => '',
        ]);
        Http::fake([
            'https://api.hoc.agency/up' => Http::response('down', 500),
        ]);

        $this->artisan('ops:watch-health')->assertSuccessful();
        $this->assertFalse((bool) Cache::get('dev.health.down'));

        $this->artisan('ops:watch-health')->assertSuccessful();

        $this->assertTrue(Cache::get('dev.health.down'));
        Http::assertSentCount(2);
        $this->assertFileExists(app(DevBeat::class)->path('scheduler'));
    }

    public function test_health_watch_clears_a_down_flag_from_the_internal_check(): void
    {
        config([
            'services.dev.health_internal_url' => 'http://hoc-edge/up',
            'services.telegram.dev_bot_token' => '',
            'services.telegram.dev_chat_id' => '',
        ]);
        Cache::put('dev.health.down', true, now()->addDay());
        Http::fake([
            'http://hoc-edge/up' => Http::response('ok', 200),
        ]);

        $this->artisan('ops:watch-health')->assertSuccessful();

        $this->assertFalse((bool) Cache::get('dev.health.down'));
        Http::assertSent(fn ($request): bool => $request->url() === 'http://hoc-edge/up');
    }

    public function test_dev_bot_ping_requires_the_webhook_secret(): void
    {
        $this->getJson('/api/bot/dev/ping')->assertUnauthorized();

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-dev'])
            ->getJson('/api/bot/dev/ping')
            ->assertOk()
            ->assertJsonPath('data.ok', true);
    }

    public function test_sentry_webhook_alerts_the_same_issue_once(): void
    {
        config([
            'services.sentry.webhook_secret' => 'sentry-secret',
            'services.telegram.dev_bot_token' => 'test-token',
            'services.telegram.dev_chat_id' => '1',
        ]);
        Http::fake();

        $payload = ['data' => ['issue' => ['id' => '9', 'title' => 'Boom']]];
        $this->withHeaders(['X-Webhook-Secret' => 'sentry-secret'])
            ->postJson('/api/integrations/sentry', $payload)
            ->assertOk();
        $this->withHeaders(['X-Webhook-Secret' => 'sentry-secret'])
            ->postJson('/api/integrations/sentry', $payload)
            ->assertOk();

        Http::assertSentCount(1);
    }

    public function test_signal_watch_alerts_once_when_a_seen_bot_goes_quiet(): void
    {
        Cache::flush();
        Http::fake();
        config([
            'services.telegram.dev_bot_token' => 'test-token',
            'services.telegram.dev_chat_id' => '1',
        ]);
        $beats = app(DevBeat::class);
        $beats->touch('client');

        $this->artisan('ops:watch-signals')->assertSuccessful();
        Http::assertNothingSent();

        touch($beats->path('client'), time() - 700);
        $this->artisan('ops:watch-signals')->assertSuccessful();
        $this->artisan('ops:watch-signals')->assertSuccessful();
        Http::assertSentCount(1);

        $beats->touch('client');
        $this->artisan('ops:watch-signals')->assertSuccessful();
        Http::assertSentCount(2);
    }

    public function test_signal_watch_alerts_a_new_failed_job_and_a_stuck_outbox_once(): void
    {
        Cache::flush();
        Http::fake();
        config([
            'services.telegram.dev_bot_token' => 'test-token',
            'services.telegram.dev_chat_id' => '1',
        ]);
        $request = ServiceRequest::factory()->create();
        IntegrationEvent::query()->create([
            'event_uuid' => '77777777-7777-7777-7777-777777777777',
            'event_type' => WorkflowEventType::DeliveryReady,
            'request_uuid' => $request->uuid,
            'request_number' => $request->number,
            'correlation_id' => '88888888-8888-8888-8888-888888888888',
            'status' => IntegrationEventStatus::Failed,
            'attempts' => 5,
            'last_error' => 'HTTP 500',
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => '99999999-9999-9999-9999-999999999999',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'boom',
            'failed_at' => now(),
        ]);

        $this->artisan('ops:watch-signals')->assertSuccessful();
        $this->artisan('ops:watch-signals')->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_drive_poll_failure_alerts_developers_once(): void
    {
        Cache::flush();
        Http::fake();
        config([
            'services.telegram.dev_bot_token' => 'test-token',
            'services.telegram.dev_chat_id' => '1',
        ]);
        ServiceRequest::factory()->create([
            'status' => RequestStatus::InProgress,
            'google_drive_folder_id' => 'folder-poll-1',
        ]);
        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('isHocClientRootId')->andReturn(false);
            $mock->shouldReceive('listNewFiles')->andThrow(new \RuntimeException('drive down'));
        });

        $this->artisan('ops:poll-drive')->assertSuccessful();
        $this->artisan('ops:poll-drive')->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_dev_digest_requires_the_webhook_secret(): void
    {
        config(['services.sentry.webhook_secret' => 'sentry-secret']);
        $this->getJson('/api/bot/dev/digest')->assertUnauthorized();

        $response = $this->withHeaders(['X-Webhook-Secret' => 'change-me-dev'])
            ->getJson('/api/bot/dev/digest')
            ->assertOk();

        $this->assertStringContainsString('ملخص المطورين', (string) $response->json('data.text'));
        $this->assertStringContainsString('POST /api/integrations/sentry', (string) $response->json('data.text'));
    }
}
