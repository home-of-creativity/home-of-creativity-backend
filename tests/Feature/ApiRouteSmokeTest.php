<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiRouteSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_bot_routes_do_not_return_500(): void
    {
        Http::fake();
        Sanctum::actingAs(User::factory()->admin()->create());

        $failures = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/admin') && ! str_starts_with($uri, 'api/bot')) {
                continue;
            }

            $path = '/'.preg_replace('/\{[^}]+\}/', '1', $uri);
            $headers = $this->secretHeaders($uri);

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $response = $this->json($method, $path, [], $headers);
                if ($response->status() >= 500) {
                    $failures[] = $method.' '.$path.' -> '.$response->status();
                }
            }
        }

        $this->assertSame([], $failures, "Routes returned 500:\n".implode("\n", $failures));
    }

    /**
     * @return array<string, string>
     */
    private function secretHeaders(string $uri): array
    {
        $secret = match (true) {
            str_starts_with($uri, 'api/bot/staff') => (string) config('services.telegram.staff_bot_secret'),
            str_starts_with($uri, 'api/bot/admin') => (string) config('services.telegram.admin_bot_secret'),
            str_starts_with($uri, 'api/bot/dev') => (string) config('services.telegram.dev_bot_secret'),
            str_starts_with($uri, 'api/bot/telegram') => (string) config('services.telegram.bot_secret'),
            default => '',
        };

        return $secret === '' ? [] : ['X-Webhook-Secret' => $secret];
    }
}
