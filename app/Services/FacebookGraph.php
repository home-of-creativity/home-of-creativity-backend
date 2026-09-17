<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

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

    public static function videoRuploadUrl(string $videoId): string
    {
        return 'https://rupload.facebook.com/video-upload/'.self::version().'/'.ltrim($videoId, '/');
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
        $candidates = [
            data_get($json, 'error.message'),
            data_get($json, 'error.error_user_msg'),
            data_get($json, 'error.error_user_title'),
            data_get($json, 'debug_info.message'),
            data_get($json, 'debug_info.type'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return 'Facebook Graph HTTP '.$status;
    }

    public static function isNewPagesExperienceError(mixed $json, ?string $message = null): bool
    {
        $message ??= self::errorMessage($json, 400);
        $subcode = data_get($json, 'error.error_subcode');

        return $subcode === 2069030 || self::isNewPagesExperienceMessage($message);
    }

    public static function isNewPagesExperienceMessage(?string $message): bool
    {
        if (! is_string($message) || $message === '') {
            return false;
        }

        $normalized = strtolower($message);

        return str_contains($normalized, 'new pages experience')
            || str_contains($normalized, 'facebook_new_pages');
    }

    public static function instagramBusinessAccountId(string $facebookPageId, string $token): ?string
    {
        if ($facebookPageId === '' || $token === '') {
            return null;
        }

        try {
            $response = Http::timeout((int) config('services.social.timeout', 20))
                ->connectTimeout(max(5, (int) config('services.social.connect_timeout', 10)))
                ->acceptJson()
                ->get(self::url($facebookPageId), self::withToken([
                    'fields' => 'instagram_business_account{id}',
                ], $token));
        } catch (ConnectionException|Throwable) {
            return null;
        }

        $id = data_get($response->json(), 'instagram_business_account.id');

        return is_string($id) && $id !== '' ? $id : null;
    }
}
