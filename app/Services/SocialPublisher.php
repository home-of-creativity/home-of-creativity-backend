<?php

namespace App\Services;

use App\Enums\SocialPlatform;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostMedia;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SocialPublisher
{
    /**
     * @return array{ok: bool, external_id: ?string, error: ?string}
     */
    public function publish(SocialAccount $account, SocialPost $post): array
    {
        if (! $account->is_active) {
            return $this->fail('Account is disabled.');
        }

        if ($account->hasToken() && in_array($account->platform, [SocialPlatform::Facebook, SocialPlatform::Instagram], true)) {
            return $this->publishViaGraph($account, $post);
        }

        return $this->publishViaN8n($account, $post);
    }

    /**
     * @return bool|null true when the remote post exists, false when it is gone, null when the check is inconclusive
     */
    public function isLive(SocialAccount $account, string $externalId): ?bool
    {
        if (! $account->hasToken()) {
            return null;
        }

        try {
            $response = Http::timeout(5)
                ->connectTimeout(3)
                ->acceptJson()
                ->get($this->graphUrl($externalId), [
                    'fields' => 'id',
                    'access_token' => $account->access_token,
                ]);
        } catch (ConnectionException|Throwable) {
            return null;
        }

        if ($response->successful() && filled(data_get($response->json(), 'id'))) {
            return true;
        }

        $code = data_get($response->json(), 'error.code');
        $subcode = data_get($response->json(), 'error.error_subcode');
        $message = strtolower((string) data_get($response->json(), 'error.message'));

        if ($response->notFound()
            || in_array($code, [12, 803], true)
            || $subcode === 33
            || str_contains($message, 'does not exist')
            || str_contains($message, 'has been deleted')) {
            return false;
        }

        return null;
    }

    /**
     * @return array{ok: bool, external_id: ?string, error: ?string}
     */
    public function updateLive(SocialAccount $account, string $externalId, SocialPost $post): array
    {
        if (! $account->hasToken()) {
            return $this->fail('Account is not connected.');
        }

        $field = $account->platform === SocialPlatform::Instagram ? 'caption' : 'message';

        try {
            $response = Http::timeout((int) config('services.social.timeout', 20))
                ->connectTimeout(3)
                ->acceptJson()
                ->asForm()
                ->post($this->graphUrl($externalId), [
                    $field => $post->body,
                    'access_token' => $account->access_token,
                ]);
        } catch (ConnectionException|Throwable $exception) {
            return $this->fail($exception->getMessage());
        }

        if (! $response->successful()) {
            return $this->fail($this->graphError($response->json(), $response->status()));
        }

        return [
            'ok' => true,
            'external_id' => $externalId,
            'error' => null,
        ];
    }

    /**
     * @return array{ok: bool, external_id: ?string, error: ?string}
     */
    public function deleteLive(SocialAccount $account, string $externalId): array
    {
        if (! $account->hasToken()) {
            return $this->fail('Account is not connected.');
        }

        try {
            $response = Http::timeout((int) config('services.social.timeout', 20))
                ->connectTimeout(3)
                ->acceptJson()
                ->delete($this->graphUrl($externalId), [
                    'access_token' => $account->access_token,
                ]);
        } catch (ConnectionException|Throwable $exception) {
            return $this->fail($exception->getMessage());
        }

        if (! $response->successful() && ! $response->notFound()) {
            return $this->fail($this->graphError($response->json(), $response->status()));
        }

        return [
            'ok' => true,
            'external_id' => $externalId,
            'error' => null,
        ];
    }

    /**
     * @return array{ok: bool, external_id: ?string, error: ?string}
     */
    public function reply(SocialAccount $account, string $externalId, string $body, string $kind): array
    {
        if (! $account->hasToken()) {
            return $this->fail('Account is not connected.');
        }

        if (! in_array($account->platform, [SocialPlatform::Facebook, SocialPlatform::Instagram], true)) {
            return $this->publishViaN8n($account, null, [
                'event' => 'social.inbox.reply',
                'external_id' => $externalId,
                'kind' => $kind,
                'body' => $body,
            ]);
        }

        $endpoint = $kind === 'comment'
            ? $externalId.'/comments'
            : $externalId.'/messages';

        try {
            $response = Http::timeout((int) config('services.social.timeout', 20))
                ->connectTimeout(3)
                ->acceptJson()
                ->asForm()
                ->post($this->graphUrl($endpoint), [
                    'message' => $body,
                    'access_token' => $account->access_token,
                ]);
        } catch (ConnectionException|Throwable $exception) {
            return $this->fail($exception->getMessage());
        }

        if (! $response->successful()) {
            return $this->fail($this->graphError($response->json(), $response->status()));
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        return [
            'ok' => true,
            'external_id' => isset($payload['id']) ? (string) $payload['id'] : null,
            'error' => null,
        ];
    }

    /**
     * @return array{ok: bool, external_id: ?string, error: ?string}
     */
    private function publishViaGraph(SocialAccount $account, SocialPost $post): array
    {
        $pageId = $account->page_id;
        if (! filled($pageId)) {
            return $this->fail('Page ID is missing.');
        }

        $media = $post->media->first();

        try {
            if ($account->platform === SocialPlatform::Instagram) {
                return $this->publishInstagram($account, $post, $pageId, $media);
            }

            return $this->publishFacebook($account, $post, $pageId, $media);
        } catch (ConnectionException|Throwable $exception) {
            return $this->fail($exception->getMessage());
        }
    }

    /**
     * @return array{ok: bool, external_id: ?string, error: ?string}
     */
    private function publishFacebook(SocialAccount $account, SocialPost $post, string $pageId, ?SocialPostMedia $media): array
    {
        $token = (string) $account->access_token;

        if (! $media) {
            return $this->graphForm($pageId.'/feed', [
                'message' => $post->body,
                'access_token' => $token,
            ]);
        }

        $disk = Storage::disk('public');
        if (! $disk->exists((string) $media->path)) {
            return $this->fail('Media file is missing.');
        }

        $isVideo = $media->kind === 'video';
        $filename = $media->original_name ?: basename((string) $media->path);
        $contents = $disk->get((string) $media->path);
        if (! is_string($contents) || $contents === '') {
            return $this->fail('Media file is empty.');
        }

        $response = Http::timeout((int) config('services.social.upload_timeout', 60))
            ->connectTimeout(10)
            ->acceptJson()
            ->attach('source', $contents, $filename)
            ->post($this->graphUrl($isVideo ? $pageId.'/videos' : $pageId.'/photos'), [
                ($isVideo ? 'description' : 'message') => $post->body,
                'access_token' => $token,
            ]);

        if (! $response->successful()) {
            return $this->fail($this->graphError($response->json(), $response->status()));
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        return [
            'ok' => true,
            'external_id' => isset($json['post_id']) ? (string) $json['post_id'] : (isset($json['id']) ? (string) $json['id'] : null),
            'error' => null,
        ];
    }

    /**
     * @param  array<string, string|null>  $payload
     * @return array{ok: bool, external_id: ?string, error: ?string}
     */
    private function graphForm(string $path, array $payload): array
    {
        $response = Http::timeout((int) config('services.social.timeout', 20))
            ->connectTimeout(3)
            ->acceptJson()
            ->asForm()
            ->post($this->graphUrl($path), $payload);

        if (! $response->successful()) {
            return $this->fail($this->graphError($response->json(), $response->status()));
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        return [
            'ok' => true,
            'external_id' => isset($json['post_id']) ? (string) $json['post_id'] : (isset($json['id']) ? (string) $json['id'] : null),
            'error' => null,
        ];
    }

    /**
     * @return array{ok: bool, external_id: ?string, error: ?string}
     */
    private function publishInstagram(SocialAccount $account, SocialPost $post, string $igUserId, ?SocialPostMedia $media): array
    {
        if (! $media) {
            return $this->fail('Instagram requires an image or video.');
        }

        $container = Http::timeout((int) config('services.social.timeout', 20))
            ->connectTimeout(3)
            ->acceptJson()
            ->asForm()
            ->post($this->graphUrl($igUserId.'/media'), [
                'caption' => $post->body,
                'image_url' => $media->url(),
                'access_token' => $account->access_token,
            ]);

        if (! $container->successful()) {
            return $this->fail($this->graphError($container->json(), $container->status()));
        }

        $creationId = data_get($container->json(), 'id');
        if (! is_string($creationId) || $creationId === '') {
            return $this->fail('Instagram did not return a media container.');
        }

        $publish = Http::timeout((int) config('services.social.timeout', 20))
            ->connectTimeout(3)
            ->acceptJson()
            ->asForm()
            ->post($this->graphUrl($igUserId.'/media_publish'), [
                'creation_id' => $creationId,
                'access_token' => $account->access_token,
            ]);

        if (! $publish->successful()) {
            return $this->fail($this->graphError($publish->json(), $publish->status()));
        }

        return [
            'ok' => true,
            'external_id' => (string) data_get($publish->json(), 'id'),
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $override
     * @return array{ok: bool, external_id: ?string, error: ?string}
     */
    private function publishViaN8n(SocialAccount $account, ?SocialPost $post, ?array $override = null): array
    {
        $url = config('services.n8n.webhook_url');
        if (! is_string($url) || $url === '') {
            return $this->fail('No access token and n8n webhook is not configured.');
        }

        $body = $override ?? [
            'event' => 'social.post.publish',
            'platform' => $account->platform->value,
            'account_id' => $account->id,
            'page_id' => $account->page_id,
            'handle' => $account->handle,
            'post_id' => $post?->id,
            'body' => $post?->body,
            'media' => $post?->media->map(fn (SocialPostMedia $item) => [
                'url' => $item->url(),
                'kind' => $item->kind,
                'name' => $item->original_name,
            ])->values()->all(),
        ];

        try {
            $response = Http::timeout((int) config('services.n8n.timeout', 12))
                ->connectTimeout(3)
                ->acceptJson()
                ->withHeaders([
                    'X-N8N-Secret' => (string) config('services.n8n.webhook_secret'),
                ])
                ->post($url, $body);
        } catch (ConnectionException|Throwable $exception) {
            Log::warning('Social n8n publish failed.', [
                'account_id' => $account->id,
                'error' => $exception->getMessage(),
            ]);

            return $this->fail($exception->getMessage());
        }

        if (! $response->successful()) {
            return $this->fail('n8n HTTP '.$response->status());
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        return [
            'ok' => true,
            'external_id' => isset($json['id']) ? (string) $json['id'] : ('n8n-'.$account->id.'-'.($post?->id ?? 'reply')),
            'error' => null,
        ];
    }

    private function graphUrl(string $path): string
    {
        $base = rtrim((string) config('services.social.graph_base', 'https://graph.facebook.com/v21.0'), '/');

        return $base.'/'.ltrim($path, '/');
    }

    private function graphError(mixed $json, int $status): string
    {
        $message = data_get($json, 'error.message');
        if (is_string($message) && str_contains($message, 'pages_manage_engagement')) {
            return 'missing_pages_manage_engagement';
        }

        return is_string($message) && $message !== ''
            ? $message
            : 'Graph API HTTP '.$status;
    }

    /**
     * @return array{ok: bool, external_id: ?string, error: ?string}
     */
    private function fail(string $error): array
    {
        return [
            'ok' => false,
            'external_id' => null,
            'error' => $error,
        ];
    }
}
