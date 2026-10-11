<?php

namespace App\Services\AppAuth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Verifies a Firebase Auth ID token (RS256) against Google's published
 * certificates, following https://firebase.google.com/docs/auth/admin/verify-id-tokens.
 */
class FirebaseIdTokenVerifier
{
    private const LEEWAY_SECONDS = 60;

    public function configured(): bool
    {
        return filled(config('services.firebase.project_id'));
    }

    /**
     * Verified claims, or null when the token is invalid for this project.
     *
     * @return array<string, mixed>|null
     */
    public function verify(string $idToken): ?array
    {
        $projectId = (string) config('services.firebase.project_id');
        if ($projectId === '') {
            throw new RuntimeException('Firebase project is not configured.');
        }

        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }
        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = $this->decodeJson($encodedHeader);
        $claims = $this->decodeJson($encodedPayload);
        $signature = $this->base64UrlDecode($encodedSignature);
        if ($header === null || $claims === null || $signature === null) {
            return null;
        }

        if (($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null)) {
            return null;
        }

        $certificate = $this->certificates()[$header['kid']] ?? null;
        if (! is_string($certificate)) {
            return null;
        }

        $publicKey = openssl_pkey_get_public($certificate);
        if ($publicKey === false) {
            return null;
        }
        $valid = openssl_verify($encodedHeader.'.'.$encodedPayload, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($valid !== 1) {
            return null;
        }

        return $this->claimsAreValid($claims, $projectId) ? $claims : null;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function claimsAreValid(array $claims, string $projectId): bool
    {
        $now = time();

        return ($claims['aud'] ?? null) === $projectId
            && ($claims['iss'] ?? null) === 'https://securetoken.google.com/'.$projectId
            && is_string($claims['sub'] ?? null)
            && $claims['sub'] !== ''
            && strlen($claims['sub']) <= 128
            && is_numeric($claims['exp'] ?? null)
            && (int) $claims['exp'] > $now - self::LEEWAY_SECONDS
            && is_numeric($claims['iat'] ?? null)
            && (int) $claims['iat'] <= $now + self::LEEWAY_SECONDS
            && (! isset($claims['auth_time']) || (int) $claims['auth_time'] <= $now + self::LEEWAY_SECONDS);
    }

    /**
     * @return array<string, string>
     */
    private function certificates(): array
    {
        $cached = Cache::get('firebase:securetoken-certs');
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        try {
            $response = Http::timeout(8)->connectTimeout(3)->acceptJson()->get((string) config('services.firebase.certs_url'));
        } catch (Throwable) {
            return [];
        }
        if (! $response->successful() || ! is_array($response->json())) {
            return [];
        }

        $certs = array_filter($response->json(), 'is_string');
        $maxAge = 3600;
        if (preg_match('/max-age=(\d+)/', (string) $response->header('Cache-Control'), $match)) {
            $maxAge = max(60, (int) $match[1]);
        }
        Cache::put('firebase:securetoken-certs', $certs, $maxAge);

        return $certs;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $segment): ?array
    {
        $raw = $this->base64UrlDecode($segment);
        if ($raw === null) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function base64UrlDecode(string $segment): ?string
    {
        $padded = strtr($segment, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }
}
