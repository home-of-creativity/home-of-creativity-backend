<?php

namespace App\Services;

use App\Models\OpsSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The Google account whose storage holds the files the API uploads to Drive.
 *
 * The service account owns the HOC Clients folders but has no storage quota, and delegation
 * needs Google Workspace. So staff connect a normal Google account once over OAuth and uploads
 * run as that account: the files count against its storage while staying in the same folders.
 * The OAuth client and refresh token come from .env (`GOOGLE_DRIVE_OAUTH_CLIENT_ID`,
 * `GOOGLE_DRIVE_OAUTH_CLIENT_SECRET`, `GOOGLE_DRIVE_REFRESH_TOKEN`).
 */
class GoogleDriveUploader
{
    public const SCOPE = 'https://www.googleapis.com/auth/drive';

    public const STATE_TTL_SECONDS = 600;

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const CLIENT_KEY = 'google_drive_oauth_client';

    private const ACCOUNT_KEY = 'google_drive_storage_account';

    /** @var array{token: string, expires: int}|null */
    private ?array $token = null;

    public function clientId(): string
    {
        $env = trim((string) config('services.google.drive_oauth_client_id'));

        return $env !== '' ? $env : (string) ($this->stored(self::CLIENT_KEY)['client_id'] ?? '');
    }

    public function clientFromEnv(): bool
    {
        return trim((string) config('services.google.drive_oauth_client_id')) !== '';
    }

    public function oauthConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    public function saveClient(string $clientId, string $clientSecret): void
    {
        $this->store(self::CLIENT_KEY, [
            'client_id' => trim($clientId),
            'client_secret' => trim($clientSecret),
        ]);
        $this->token = null;
    }

    public static function redirectUri(): string
    {
        $configured = trim((string) config('services.google.drive_oauth_redirect_uri'));

        return $configured !== '' ? $configured : rtrim((string) config('app.url'), '/').'/auth/google-drive/callback';
    }

    public static function dashboardUrl(): string
    {
        $configured = trim((string) config('services.google.drive_dashboard_url'));
        if ($configured !== '') {
            return $configured;
        }

        // The dashboard is served on the site host, never on api.*.
        $site = preg_replace('#^(https?://)api\.#i', '$1', rtrim((string) config('app.url'), '/'));

        return $site.'/dashboard/reports';
    }

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
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            // A refresh token, and the account chooser even when one account is signed in.
            'access_type' => 'offline',
            'prompt' => 'consent select_account',
            'state' => $state,
        ]);
    }

    /**
     * @return array{refresh_token: string, access_token: string, expires_in: int}|string Tokens, or an error code.
     */
    public function exchangeCode(string $code): array|string
    {
        try {
            $response = Http::asForm()
                ->timeout(20)
                ->connectTimeout(5)
                ->acceptJson()
                ->post(self::TOKEN_URL, [
                    'code' => $code,
                    'client_id' => $this->clientId(),
                    'client_secret' => $this->clientSecret(),
                    'redirect_uri' => self::redirectUri(),
                    'grant_type' => 'authorization_code',
                ]);
        } catch (Throwable $exception) {
            Log::warning('Google Drive OAuth token exchange failed.', ['error' => $exception->getMessage()]);

            return 'token_exchange';
        }

        if (! $response->successful()) {
            Log::warning('Google Drive OAuth token exchange rejected.', [
                'status' => $response->status(),
                'error' => $response->json('error'),
            ]);

            return 'token_exchange';
        }

        $scopes = explode(' ', (string) $response->json('scope', ''));
        if (! in_array(self::SCOPE, $scopes, true)) {
            return 'scope';
        }

        $refresh = $response->json('refresh_token');
        $access = $response->json('access_token');
        if (! is_string($refresh) || $refresh === '' || ! is_string($access) || $access === '') {
            return 'no_refresh_token';
        }

        return [
            'refresh_token' => $refresh,
            'access_token' => $access,
            'expires_in' => (int) $response->json('expires_in', 3600),
        ];
    }

    /**
     * @return array{email: string, limit: ?int, usage: int}|null
     */
    public function about(string $accessToken): ?array
    {
        try {
            $response = Http::withToken($accessToken)
                ->timeout(15)
                ->connectTimeout(5)
                ->acceptJson()
                ->get('https://www.googleapis.com/drive/v3/about', [
                    'fields' => 'user(emailAddress),storageQuota(limit,usage)',
                ]);
        } catch (Throwable) {
            return null;
        }

        $email = $response->json('user.emailAddress');
        if (! $response->successful() || ! is_string($email) || $email === '') {
            return null;
        }

        $limit = $response->json('storageQuota.limit');

        return [
            'email' => $email,
            'limit' => is_numeric($limit) ? (int) $limit : null,
            'usage' => (int) $response->json('storageQuota.usage', 0),
        ];
    }

    /**
     * @param  array{refresh_token: string, access_token: string, expires_in: int}  $tokens
     */
    public function connect(array $tokens, string $email): void
    {
        $this->store(self::ACCOUNT_KEY, [
            'email' => $email,
            'refresh_token' => $tokens['refresh_token'],
            'connected_at' => now()->toIso8601String(),
            'error' => null,
        ]);
        $this->token = [
            'token' => $tokens['access_token'],
            'expires' => time() + $tokens['expires_in'],
        ];
    }

    public function disconnect(): void
    {
        $refresh = $this->stored(self::ACCOUNT_KEY)['refresh_token'] ?? null;
        if (is_string($refresh) && $refresh !== '') {
            try {
                Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/revoke', ['token' => $refresh]);
            } catch (Throwable) {
                // Forgetting the token below is what matters; Google also drops unused tokens.
            }
        }

        OpsSetting::setValue(self::ACCOUNT_KEY, null);
        $this->token = null;
    }

    public function connected(): bool
    {
        return $this->refreshToken() !== '';
    }

    public function email(): ?string
    {
        $env = trim((string) config('services.google.drive_storage_email'));
        if ($env !== '') {
            return $env;
        }

        $email = $this->stored(self::ACCOUNT_KEY)['email'] ?? null;

        return is_string($email) && $email !== '' ? $email : null;
    }

    /**
     * An access token for the connected account, or null when none is connected or Google
     * refused the refresh token (the reason is kept for the dashboard).
     */
    public function accessToken(): ?string
    {
        if ($this->token !== null && time() < $this->token['expires'] - 60) {
            return $this->token['token'];
        }

        $refresh = $this->refreshToken();
        $account = $this->usingEnvAccount() ? [] : $this->stored(self::ACCOUNT_KEY);
        if ($refresh === '' || ! $this->oauthConfigured()) {
            return null;
        }

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->connectTimeout(5)
                ->acceptJson()
                ->post(self::TOKEN_URL, [
                    'client_id' => $this->clientId(),
                    'client_secret' => $this->clientSecret(),
                    'refresh_token' => $refresh,
                    'grant_type' => 'refresh_token',
                ]);
        } catch (Throwable $exception) {
            Log::warning('Google Drive storage account token refresh failed.', ['error' => $exception->getMessage()]);

            return null;
        }

        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            $error = (string) $response->json('error', 'HTTP '.$response->status());
            Log::warning('Google Drive storage account token refresh rejected.', ['error' => $error]);
            if ($error === 'invalid_grant' && ! $this->usingEnvAccount()) {
                $this->store(self::ACCOUNT_KEY, [...$account, 'error' => 'Google no longer accepts this connection. Set GOOGLE_DRIVE_REFRESH_TOKEN in the server environment.']);
            }

            return null;
        }

        if (($account['error'] ?? null) !== null) {
            $this->store(self::ACCOUNT_KEY, [...$account, 'error' => null]);
        }
        $this->token = [
            'token' => $token,
            'expires' => time() + (int) $response->json('expires_in', 3600),
        ];

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $account = $this->stored(self::ACCOUNT_KEY);
        $connected = filled($account['refresh_token'] ?? null);
        $token = $connected ? $this->accessToken() : null;
        $about = $token !== null ? $this->about($token) : null;
        // A refresh failure above may have stored a new reason.
        $account = $this->stored(self::ACCOUNT_KEY);

        return [
            'oauth_configured' => $this->oauthConfigured(),
            'client_id' => $this->clientId() !== '' ? $this->clientId() : null,
            'client_from_env' => $this->clientFromEnv(),
            'redirect_uri' => self::redirectUri(),
            'connected' => $connected,
            'email' => $account['email'] ?? null,
            'connected_at' => $account['connected_at'] ?? null,
            'working' => $about !== null,
            'error' => $connected && $about === null
                ? ($account['error'] ?? 'Google Drive did not answer for this account.')
                : null,
            'storage' => $about !== null ? ['limit' => $about['limit'], 'usage' => $about['usage']] : null,
        ];
    }

    private function usingEnvAccount(): bool
    {
        return trim((string) config('services.google.drive_refresh_token')) !== '';
    }

    private function refreshToken(): string
    {
        $env = trim((string) config('services.google.drive_refresh_token'));
        if ($env !== '') {
            return $env;
        }

        $stored = $this->stored(self::ACCOUNT_KEY)['refresh_token'] ?? null;

        return is_string($stored) ? trim($stored) : '';
    }

    private function clientSecret(): string
    {
        $env = trim((string) config('services.google.drive_oauth_client_secret'));

        return $env !== '' ? $env : (string) ($this->stored(self::CLIENT_KEY)['client_secret'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(string $key): array
    {
        $raw = OpsSetting::getValue($key);
        if ($raw === null) {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($raw), true);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function store(string $key, array $value): void
    {
        OpsSetting::setValue($key, Crypt::encryptString((string) json_encode($value)));
    }

    private function stateKey(string $state): string
    {
        return 'google_drive_oauth_state:'.$state;
    }
}
