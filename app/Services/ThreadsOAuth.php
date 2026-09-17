<?php

namespace App\Services;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ThreadsOAuth
{
    public const STATE_TTL_SECONDS = 600;

    public function rememberState(int $userId): string
    {
        $state = Str::random(40);
        Cache::put($this->stateKey($state), ['user_id' => $userId], self::STATE_TTL_SECONDS);

        return $state;
    }

    public function pullUserId(string $state): ?int
    {
        $payload = Cache::pull($this->stateKey($state));
        $userId = is_array($payload) ? data_get($payload, 'user_id') : null;

        return is_numeric($userId) ? (int) $userId : null;
    }

    public function authorizeUrl(string $state): string
    {
        $query = http_build_query([
            'client_id' => (string) config('services.threads.app_id'),
            'redirect_uri' => ThreadsGraph::redirectUri(),
            'scope' => $this->scopes(),
            'response_type' => 'code',
            'state' => $state,
        ]);

        return rtrim((string) config('services.threads.oauth_authorize'), '?').'?'.$query;
    }

    /**
     * @return array{access_token: string, expires_at: DateTimeInterface|null}|null
     */
    public function exchangeCode(string $code): ?array
    {
        $short = $this->requestShortLivedToken($code);
        if ($short === null) {
            return null;
        }

        $long = $this->requestLongLivedToken($short['access_token']);
        $accessToken = is_array($long) ? ($long['access_token'] ?? $short['access_token']) : $short['access_token'];
        $expiresIn = is_array($long)
            ? ($long['expires_in'] ?? $short['expires_in'] ?? null)
            : ($short['expires_in'] ?? null);

        if (! is_string($accessToken) || $accessToken === '') {
            return null;
        }

        return [
            'access_token' => $accessToken,
            'expires_at' => is_numeric($expiresIn) ? Carbon::now()->addSeconds((int) $expiresIn) : null,
        ];
    }

    public function dashboardRedirect(string $result): RedirectResponse
    {
        $url = ThreadsGraph::dashboardAccountsUrl();
        $query = $result === 'connected'
            ? ['threads' => 'connected']
            : ['threads_error' => $result];
        $separator = str_contains($url, '?') ? '&' : '?';

        return redirect()->away($url.$separator.http_build_query($query));
    }

    /**
     * @return array{access_token: string, expires_in?: int}|null
     */
    private function requestShortLivedToken(string $code): ?array
    {
        try {
            $response = Http::timeout((int) config('services.social.timeout', 20))
                ->connectTimeout(3)
                ->retry([100, 300])
                ->acceptJson()
                ->asForm()
                ->post(ThreadsGraph::oauthHost().'/oauth/access_token', [
                    'client_id' => (string) config('services.threads.app_id'),
                    'client_secret' => (string) config('services.threads.app_secret'),
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => ThreadsGraph::redirectUri(),
                    'code' => $code,
                ]);
        } catch (ConnectionException|Throwable $exception) {
            Log::warning('Threads OAuth token exchange failed.', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Threads OAuth token exchange rejected.', [
                'status' => $response->status(),
            ]);

            return null;
        }

        $token = data_get($response->json(), 'access_token');
        if (! is_string($token) || $token === '') {
            return null;
        }

        $expiresIn = data_get($response->json(), 'expires_in');

        return [
            'access_token' => $token,
            'expires_in' => is_numeric($expiresIn) ? (int) $expiresIn : null,
        ];
    }

    /**
     * @return array{access_token: string, expires_in?: int}|null
     */
    private function requestLongLivedToken(string $shortLivedToken): ?array
    {
        try {
            $response = Http::timeout((int) config('services.social.timeout', 20))
                ->connectTimeout(3)
                ->retry([100, 300])
                ->acceptJson()
                ->get(ThreadsGraph::oauthHost().'/access_token', [
                    'grant_type' => 'th_exchange_token',
                    'client_secret' => (string) config('services.threads.app_secret'),
                    'access_token' => $shortLivedToken,
                ]);
        } catch (ConnectionException|Throwable $exception) {
            Log::warning('Threads OAuth long-lived token exchange failed.', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Threads OAuth long-lived token exchange rejected.', [
                'status' => $response->status(),
            ]);

            return null;
        }

        $token = data_get($response->json(), 'access_token');
        if (! is_string($token) || $token === '') {
            return null;
        }

        $expiresIn = data_get($response->json(), 'expires_in');

        return [
            'access_token' => $token,
            'expires_in' => is_numeric($expiresIn) ? (int) $expiresIn : null,
        ];
    }

    private function scopes(): string
    {
        $scopes = array_values(array_filter(array_map(
            static fn (string $scope): string => trim($scope),
            explode(',', (string) config('services.threads.scopes')),
        )));

        return implode(',', $scopes);
    }

    private function stateKey(string $state): string
    {
        return 'threads_oauth_state:'.$state;
    }
}
