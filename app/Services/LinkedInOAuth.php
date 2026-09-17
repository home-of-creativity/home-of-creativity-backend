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

class LinkedInOAuth
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
            'response_type' => 'code',
            'client_id' => (string) config('services.linkedin.client_id'),
            'redirect_uri' => LinkedInGraph::redirectUri(),
            'state' => $state,
            'scope' => $this->scopes(),
        ]);

        return LinkedInGraph::oauthHost().'/authorization?'.$query;
    }

    /**
     * @return array{access_token: string, expires_at: DateTimeInterface|null}|null
     */
    public function exchangeCode(string $code): ?array
    {
        try {
            $response = Http::timeout((int) config('services.social.timeout', 20))
                ->connectTimeout(3)
                ->retry([100, 300])
                ->acceptJson()
                ->asForm()
                ->post(LinkedInGraph::oauthHost().'/accessToken', [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => LinkedInGraph::redirectUri(),
                    'client_id' => (string) config('services.linkedin.client_id'),
                    'client_secret' => (string) config('services.linkedin.client_secret'),
                ]);
        } catch (ConnectionException|Throwable $exception) {
            Log::warning('LinkedIn OAuth token exchange failed.', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('LinkedIn OAuth token exchange rejected.', [
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
            'expires_at' => is_numeric($expiresIn) ? Carbon::now()->addSeconds((int) $expiresIn) : null,
        ];
    }

    public function dashboardRedirect(string $result): RedirectResponse
    {
        $url = LinkedInGraph::dashboardAccountsUrl();
        $query = $result === 'connected'
            ? ['linkedin' => 'connected']
            : ['linkedin_error' => $result];
        $separator = str_contains($url, '?') ? '&' : '?';

        return redirect()->away($url.$separator.http_build_query($query));
    }

    private function scopes(): string
    {
        $scopes = array_values(array_filter(array_map(
            static fn (string $scope): string => trim($scope),
            explode(',', (string) config('services.linkedin.scopes')),
        )));

        return implode(' ', $scopes);
    }

    private function stateKey(string $state): string
    {
        return 'linkedin_oauth_state:'.$state;
    }
}
