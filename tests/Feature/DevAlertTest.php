<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
        Http::fake([
            'https://api.hoc.agency/up' => Http::response('down', 500),
        ]);

        $this->artisan('ops:watch-health')->assertSuccessful();
        $this->artisan('ops:watch-health')->assertSuccessful();

        $this->assertTrue(Cache::get('dev.health.down'));
        Http::assertSentCount(2);
    }

    public function test_dev_bot_ping_requires_the_webhook_secret(): void
    {
        $this->getJson('/api/bot/dev/ping')->assertUnauthorized();

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-dev'])
            ->getJson('/api/bot/dev/ping')
            ->assertOk()
            ->assertJsonPath('data.ok', true);
    }
}
