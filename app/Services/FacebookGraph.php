<?php

namespace App\Services;

class FacebookGraph
{
    public static function configured(): bool
    {
        return filled(config('services.facebook.access_token'))
            && filled(config('services.facebook.app_id'))
            && filled(config('services.facebook.app_secret'));
    }

    public static function version(): string
    {
        $base = rtrim((string) config('services.social.graph_base', 'https://graph.facebook.com/v21.0'), '/');
        if (preg_match('#/(v\d+\.\d+)$#', $base, $matches) === 1) {
            return $matches[1];
        }

        return 'v21.0';
    }

    public static function url(string $path): string
    {
        $base = rtrim((string) config('services.social.graph_base', 'https://graph.facebook.com/v21.0'), '/');

        return $base.'/'.ltrim($path, '/');
    }

    public static function ruploadUrl(string $containerId): string
    {
        return 'https://rupload.facebook.com/ig-api-upload/'.self::version().'/'.ltrim($containerId, '/');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public static function withToken(array $query, string $token): array
    {
        $query['access_token'] = $token;

        $secret = trim((string) config('services.facebook.app_secret'));
        if ($secret !== '') {
            $query['appsecret_proof'] = hash_hmac('sha256', $token, $secret);
        }

        return $query;
    }

    public static function errorMessage(mixed $json, int $status): string
    {
        $message = data_get($json, 'error.message');

        return is_string($message) && $message !== ''
            ? $message
            : 'Facebook Graph HTTP '.$status;
    }
}
