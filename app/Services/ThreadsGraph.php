<?php

namespace App\Services;

class ThreadsGraph
{
    public static function configured(): bool
    {
        return filled(config('services.threads.access_token')) || self::oauthConfigured();
    }

    public static function oauthConfigured(): bool
    {
        return filled(config('services.threads.app_id'))
            && filled(config('services.threads.app_secret'));
    }

    public static function redirectUri(): string
    {
        $configured = trim((string) config('services.threads.redirect_uri'));
        if ($configured !== '') {
            return $configured;
        }

        return rtrim((string) config('app.url'), '/').'/auth/threads/callback';
    }

    public static function dashboardAccountsUrl(): string
    {
        $configured = trim((string) config('services.threads.dashboard_accounts_url'));
        if ($configured !== '') {
            return $configured;
        }

        return 'https://hoc.agency/dashboard/social/accounts';
    }

    public static function url(string $path): string
    {
        $base = rtrim((string) config('services.social.threads_graph_base', 'https://graph.threads.net/v1.0'), '/');

        return $base.'/'.ltrim($path, '/');
    }

    public static function oauthHost(): string
    {
        return rtrim((string) config('services.threads.oauth_token_base', 'https://graph.threads.net'), '/');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public static function withToken(array $query, string $token): array
    {
        $query['access_token'] = $token;

        return $query;
    }

    public static function errorMessage(mixed $json, int $status): string
    {
        $message = data_get($json, 'error.message');

        return is_string($message) && $message !== ''
            ? $message
            : 'Threads Graph HTTP '.$status;
    }
}
