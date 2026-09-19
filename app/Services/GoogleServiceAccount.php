<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GoogleServiceAccount
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPES = [
        'https://www.googleapis.com/auth/drive',
        'https://www.googleapis.com/auth/calendar',
    ];

    public const SEARCH_CONSOLE_SCOPE = 'https://www.googleapis.com/auth/webmasters';

    /** @var array<string, array{token: string, expires: int}> */
    private array $tokens = [];

    public function configured(): bool
    {
        return $this->credentials() !== null;
    }

    public function configurationError(): ?string
    {
        $raw = config('services.google.credentials_json');
        if (! filled($raw)) {
            return 'Google service account JSON is missing. Set GOOGLE_SERVICE_ACCOUNT_JSON to the JSON file path.';
        }

        if ($this->credentials() !== null) {
            return null;
        }

        $value = $this->normalizedCredentialValue((string) $raw);
        if (! str_starts_with($value, '{') && $this->resolveCredentialsPath($value) === null) {
            return 'Google service account JSON file was not found. Check GOOGLE_SERVICE_ACCOUNT_JSON.';
        }

        return 'Google service account JSON is invalid. The file must include client_email and private_key.';
    }

    public function accessToken(): ?string
    {
        return $this->tokenFor(self::SCOPES);
    }

    public function searchConsoleToken(): ?string
    {
        return $this->tokenFor([self::SEARCH_CONSOLE_SCOPE]);
    }

    /**
     * @param  list<string>  $scopes
     */
    private function tokenFor(array $scopes): ?string
    {
        if (! $this->configured()) {
            return null;
        }

        $cacheKey = implode(' ', $scopes);
        $cached = $this->tokens[$cacheKey] ?? null;
        if ($cached !== null && time() < ($cached['expires'] - 60)) {
            return $cached['token'];
        }

        try {
            $credentials = $this->credentials();
            if ($credentials === null) {
                return null;
            }

            $now = time();
            $jwtHeader = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
            $jwtClaim = $this->base64UrlEncode(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => implode(' ', $scopes),
                'aud' => self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ], JSON_THROW_ON_ERROR));

            $unsigned = $jwtHeader.'.'.$jwtClaim;
            $signature = '';
            $ok = openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256);
            if (! $ok) {
                Log::warning('Google service account JWT signing failed.');

                return null;
            }

            $assertion = $unsigned.'.'.$this->base64UrlEncode($signature);

            $response = Http::asForm()
                ->timeout(15)
                ->connectTimeout(5)
                ->post(self::TOKEN_URL, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ]);

            if (! $response->successful()) {
                Log::warning('Google service account token exchange failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $token = (string) $response->json('access_token', '');
            if ($token === '') {
                return null;
            }

            $this->tokens[$cacheKey] = [
                'token' => $token,
                'expires' => $now + (int) $response->json('expires_in', 3600),
            ];

            return $token;
        } catch (Throwable $exception) {
            Log::warning('Google service account token failed.', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array{client_email: string, private_key: string}|null
     */
    private function credentials(): ?array
    {
        $raw = config('services.google.credentials_json');
        if (! filled($raw)) {
            return null;
        }

        $json = $this->normalizedCredentialValue((string) $raw);
        $path = str_starts_with($json, '{') ? null : $this->resolveCredentialsPath($json);
        if ($path !== null) {
            $contents = @file_get_contents($path);
            if ($contents === false || $contents === '') {
                return null;
            }
            $json = $contents;
        } elseif (! str_starts_with($json, '{')) {
            $decodedB64 = base64_decode($json, true);
            if (is_string($decodedB64) && str_starts_with(ltrim($decodedB64), '{')) {
                $json = $decodedB64;
            }
        }

        try {
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($decoded)
            || ! filled($decoded['client_email'] ?? null)
            || ! filled($decoded['private_key'] ?? null)) {
            return null;
        }

        $privateKey = (string) $decoded['private_key'];
        if (! str_contains($privateKey, "\n") && str_contains($privateKey, '\\n')) {
            $privateKey = str_replace('\\n', "\n", $privateKey);
        }

        return [
            'client_email' => (string) $decoded['client_email'],
            'private_key' => $privateKey,
        ];
    }

    private function normalizedCredentialValue(string $raw): string
    {
        return trim($raw, " \t\n\r\"'");
    }

    private function resolveCredentialsPath(string $value): ?string
    {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $value);
        $candidates = array_unique([
            $value,
            $normalized,
            base_path($value),
            base_path($normalized),
            storage_path('app'.DIRECTORY_SEPARATOR.ltrim($normalized, DIRECTORY_SEPARATOR)),
        ]);

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
