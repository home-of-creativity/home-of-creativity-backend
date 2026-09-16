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

    private ?string $cachedToken = null;

    private ?int $expiresAt = null;

    public function configured(): bool
    {
        return $this->credentials() !== null;
    }

    public function accessToken(): ?string
    {
        if (! $this->configured()) {
            return null;
        }

        if ($this->cachedToken !== null && $this->expiresAt !== null && time() < ($this->expiresAt - 60)) {
            return $this->cachedToken;
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
                'scope' => implode(' ', self::SCOPES),
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

            $this->cachedToken = $token;
            $this->expiresAt = $now + (int) $response->json('expires_in', 3600);

            return $this->cachedToken;
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

        $json = (string) $raw;
        if (is_file($json)) {
            $contents = @file_get_contents($json);
            if ($contents === false || $contents === '') {
                return null;
            }
            $json = $contents;
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

        return [
            'client_email' => (string) $decoded['client_email'],
            'private_key' => (string) $decoded['private_key'],
        ];
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
