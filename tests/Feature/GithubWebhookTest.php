<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GithubWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.github.webhook_secret' => 'github-test-secret',
            'services.telegram.dev_bot_token' => 'test-token',
            'services.telegram.dev_chat_id' => '42',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);
    }

    public function test_a_bad_signature_is_rejected(): void
    {
        $this->postJson('/api/integrations/github', ['action' => 'completed'], [
            'X-Hub-Signature-256' => 'sha256=nope',
            'X-GitHub-Event' => 'workflow_job',
        ])->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_a_failed_test_job_names_the_step_and_a_successful_deploy_says_so(): void
    {
        $this->postSigned('delivery-fail', [
            'action' => 'completed',
            'repository' => ['full_name' => 'home-of-creativity/home-of-creativity-backend'],
            'workflow_job' => [
                'name' => 'test',
                'conclusion' => 'failure',
                'html_url' => 'https://github.com/runs/1',
                'steps' => [
                    ['name' => 'Checkout', 'conclusion' => 'success'],
                    ['name' => 'Run API and bot tests', 'conclusion' => 'failure'],
                ],
            ],
        ])->assertOk();

        $this->postSigned('delivery-ok', [
            'action' => 'completed',
            'repository' => ['full_name' => 'home-of-creativity/home-of-creativity-backend'],
            'workflow_job' => [
                'name' => 'deploy',
                'conclusion' => 'success',
                'html_url' => 'https://github.com/runs/2',
                'steps' => [
                    ['name' => 'Upload code', 'conclusion' => 'success'],
                    ['name' => 'Run server script', 'conclusion' => 'success'],
                ],
            ],
        ])->assertOk();

        $bodies = $this->sentTexts();
        $this->assertStringContainsString('Run API and bot tests', $bodies);
        $this->assertStringContainsString('نجح', $bodies);
        $this->assertStringContainsString('deploy', $bodies);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-dev'])
            ->getJson('/api/bot/dev/status')
            ->assertOk()
            ->assertJsonPath('data.github_last', fn (mixed $text): bool => is_string($text) && str_contains($text, 'نجح'));
    }

    public function test_a_skipped_schedule_and_a_repeated_delivery_stay_silent(): void
    {
        $payload = [
            'action' => 'completed',
            'repository' => ['full_name' => 'home-of-creativity/home-of-creativity-profile'],
            'workflow_job' => [
                'name' => 'deploy',
                'conclusion' => 'success',
                'steps' => [
                    ['name' => 'Fingerprint CMS content', 'conclusion' => 'success'],
                    ['name' => 'Checkout', 'conclusion' => 'skipped'],
                    ['name' => 'Build static export', 'conclusion' => 'skipped'],
                ],
            ],
        ];

        $this->postSigned('delivery-skip', $payload)->assertOk()->assertJsonPath('data.ignored', 'schedule');
        $this->postSigned('delivery-fail', [
            'action' => 'completed',
            'repository' => ['full_name' => 'home-of-creativity/home-of-creativity-backend'],
            'workflow_job' => [
                'name' => 'test',
                'conclusion' => 'failure',
                'steps' => [
                    ['name' => 'Run API and bot tests', 'conclusion' => 'failure'],
                ],
            ],
        ])->assertOk();
        $this->postSigned('delivery-fail', [
            'action' => 'completed',
            'repository' => ['full_name' => 'home-of-creativity/home-of-creativity-backend'],
            'workflow_job' => [
                'name' => 'test',
                'conclusion' => 'failure',
                'steps' => [
                    ['name' => 'Run API and bot tests', 'conclusion' => 'failure'],
                ],
            ],
        ])->assertOk()->assertJsonPath('data.ignored', 'duplicate');

        Http::assertSentCount(1);
        $this->assertIsString(Cache::get('dev.github.last'));
        $this->assertStringContainsString('Run API and bot tests', (string) Cache::get('dev.github.last'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postSigned(string $delivery, array $payload): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $signature = 'sha256='.hash_hmac('sha256', (string) $body, 'github-test-secret');

        return $this->call(
            'POST',
            '/api/integrations/github',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
                'HTTP_X_GITHUB_EVENT' => 'workflow_job',
                'HTTP_X_GITHUB_DELIVERY' => $delivery,
            ],
            (string) $body,
        );
    }

    private function sentTexts(): string
    {
        $texts = [];
        Http::recorded(function (\Illuminate\Http\Client\Request $request) use (&$texts): void {
            $text = data_get($request->data(), 'text');
            if (is_string($text)) {
                $texts[] = $text;
            }
        });

        return implode("\n", $texts);
    }
}
