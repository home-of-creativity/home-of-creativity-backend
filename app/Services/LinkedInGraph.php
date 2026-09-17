<?php

namespace App\Services;

class LinkedInGraph
{
    public static function configured(): bool
    {
        return self::oauthConfigured();
    }

    public static function oauthConfigured(): bool
    {
        return filled(config('services.linkedin.client_id'))
            && filled(config('services.linkedin.client_secret'));
    }

    public static function redirectUri(): string
    {
        $configured = trim((string) config('services.linkedin.redirect_uri'));
        if ($configured !== '') {
            return $configured;
        }

        return rtrim((string) config('app.url'), '/').'/auth/linkedin/callback';
    }

    public static function dashboardAccountsUrl(): string
    {
        $configured = trim((string) config('services.linkedin.dashboard_accounts_url'));
        if ($configured !== '') {
            return $configured;
        }

        return 'https://hoc.agency/dashboard/social/accounts';
    }

    public static function apiUrl(string $path): string
    {
        $base = rtrim((string) config('services.linkedin.api_base', 'https://api.linkedin.com/v2'), '/');

        return $base.'/'.ltrim($path, '/');
    }

    public static function restUrl(string $path): string
    {
        $base = rtrim((string) config('services.linkedin.rest_base', 'https://api.linkedin.com/rest'), '/');

        return $base.'/'.ltrim($path, '/');
    }

    public static function oauthHost(): string
    {
        return rtrim((string) config('services.linkedin.oauth_host', 'https://www.linkedin.com/oauth/v2'), '/');
    }

    public static function version(): string
    {
        return (string) config('services.linkedin.api_version', '202409');
    }

    /**
     * @return array<string, string>
     */
    public static function authHeaders(string $token): array
    {
        return [
            'Authorization' => 'Bearer '.$token,
            'LinkedIn-Version' => self::version(),
            'X-Restli-Protocol-Version' => '2.0.0',
        ];
    }

    public static function organizationUrn(string $organizationId): string
    {
        return str_starts_with($organizationId, 'urn:li:organization:')
            ? $organizationId
            : 'urn:li:organization:'.$organizationId;
    }

    public static function errorMessage(mixed $json, int $status): string
    {
        $message = data_get($json, 'message');

        return is_string($message) && $message !== ''
            ? $message
            : 'LinkedIn API HTTP '.$status;
    }
}
