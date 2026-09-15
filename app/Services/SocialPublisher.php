<?php

namespace App\Services;

use App\Enums\SocialPlacement;
use App\Enums\SocialPlatform;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostMedia;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
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
                ->get(FacebookGraph::url($externalId), FacebookGraph::withToken(['fields' => 'id'], (string) $account->access_token));
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
                ->post(
                    FacebookGraph::url($externalId),
                    FacebookGraph::withToken([$field => $post->body], (string) $account->access_token),
                );
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

        $token = (string) $account->access_token;
        $candidates = [$externalId];
        $pageId = (string) ($account->page_id ?: $account->facebook_page_id ?: '');
        if ($pageId !== '' && ! str_contains($externalId, '_')) {
            $candidates[] = $pageId.'_'.$externalId;
        }

        $lastStatus = 400;
        $lastJson = null;

        foreach ($candidates as $id) {
            try {
                $response = Http::timeout((int) config('services.social.timeout', 20))
                    ->connectTimeout(3)
                    ->acceptJson()
                    ->delete(FacebookGraph::url($id), FacebookGraph::withToken([], $token));
            } catch (ConnectionException|Throwable $exception) {
                return $this->fail($exception->getMessage());
            }

            if ($response->successful() || $this->graphObjectGone($response)) {
                return [
                    'ok' => true,
                    'external_id' => $externalId,
                    'error' => null,
                ];
            }

            $lastStatus = $response->status();
            $lastJson = $response->json();
        }

        return $this->fail($this->graphError($lastJson, $lastStatus));
    }

    /**
     * @return array{ok: bool, external_id: ?string, error: ?string}
     */
    public function reply(SocialAccount $account, string $externalId, string $body, string $kind, ?string $recipientId = null): array
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
                'recipient_id' => $recipientId,
            ]);
        }

        $token = (string) $account->access_token;

        if ($kind === 'comment') {
            return $this->graphForm($externalId.'/comments', [
                'message' => $body,
            ], $token);
        }

        if (! filled($recipientId) || ! filled($account->page_id)) {
            return $this->fail('missing_message_recipient');
        }

        return $this->graphForm($account->page_id.'/messages', [
            'recipient' => json_encode(['id' => $recipientId], JSON_UNESCAPED_UNICODE),
            'messaging_type' => 'RESPONSE',
            'message' => json_encode(['text' => $body], JSON_UNESCAPED_UNICODE),
        ], $token);
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

        $media = $post->media;
        $placement = $post->placement instanceof SocialPlacement ? $post->placement : SocialPlacement::Feed;

        try {
            if ($account->platform === SocialPlatform::Instagram) {
                return $this->publishInstagram($account, $post, $pageId, $media, $placement);
            }

            return $this->publishFacebook($account, $post, $pageId, $media, $placement);
        } catch (ConnectionException|Throwable $exception) {
            return $this->fail($exception->getMessage());
        }
    }

    /**
     * @param  Collection<int, SocialPostMedia>  $media
     * @return array{ok: bool, external_id: ?string, error: ?string}
     */
    private function publishFacebook(SocialAccount $account, SocialPost $post, string $pageId, Collection $media, SocialPlacement $placement): array
    {
        $token = (string) $account->access_token;
        $first = $media->first();
        $images = $media->where('kind', 'image')->values();
        $videos = $media->where('kind', 'video')->values();

        if ($placement === SocialPlacement::Story) {
            if (! $first) {
                return $this->fail('story_needs_media');
            }

            $upload = $this->uploadFacebookFile($pageId, $token, $first, unpublished: true);
            if (! $upload['ok'] || ! filled($upload['id'])) {
                return $this->fail($upload['error'] ?? 'Media file is missing.');
            }

            $field = $first->kind === 'video' ? 'video_id' : 'photo_id';

            return $this->graphForm($pageId.'/stories', [
                $field => $upload['id'],
            ], $token);
        }

        if ($placement === SocialPlacement::Reel) {
            $video = $videos->first();
            if (! $video) {
                return $this->fail('reel_needs_video');
            }

            return $this->uploadFacebookFile($pageId, $token, $video, unpublished: false, caption: $post->body);
        }

        if ($images->count() >= 2 && $videos->isEmpty()) {
            $attached = [];
            foreach ($images as $image) {
                $upload = $this->uploadFacebookFile($pageId, $token, $image, unpublished: true);
                if (! $upload['ok'] || ! filled($upload['id'])) {
                    return $this->fail($upload['error'] ?? 'Media file is missing.');
                }
                $attached[] = ['media_fbid' => $upload['id']];
            }

            return $this->graphForm($pageId.'/feed', [
                'message' => $post->body,
                'attached_media' => json_encode($attached),
            ], $token);
        }

        if (! $first) {
            return $this->graphForm($pageId.'/feed', [
                'message' => $post->body,
            ], $token);
        }

        return $this->uploadFacebookFile($pageId, $token, $first, unpublished: false, caption: $post->body);
    }

    /**
     * @param  array<string, string|null>  $payload
     * @return array{ok: bool, external_id: ?string, error: ?string}
     */
    private function graphForm(string $path, array $payload, string $token): array
    {
        $response = Http::timeout((int) config('services.social.timeout', 20))
            ->connectTimeout(3)
            ->acceptJson()
            ->asForm()
            ->post(FacebookGraph::url($path), FacebookGraph::withToken($payload, $token));

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
     * @param  Collection<int, SocialPostMedia>  $media
     * @return array{ok: bool, external_id: ?string, error: ?string}
     */
    private function publishInstagram(SocialAccount $account, SocialPost $post, string $igUserId, Collection $media, SocialPlacement $placement): array
    {
        $first = $media->first();
        if (! $first) {
            return $this->fail($placement === SocialPlacement::Story ? 'story_needs_media' : 'Instagram requires an image or video.');
        }

        $token = (string) $account->access_token;
        $videos = $media->where('kind', 'video')->values();

        if ($placement === SocialPlacement::Reel && $videos->isEmpty()) {
            return $this->fail('reel_needs_video');
        }

        if ($placement === SocialPlacement::Story) {
            return $this->publishInstagramContainer($account, $igUserId, $first, [
                'media_type' => 'STORIES',
            ], $token);
        }

        if ($media->count() >= 2) {
            return $this->publishInstagramCarousel($account, $post, $igUserId, $media, $token);
        }

        $isVideo = $first->kind === 'video' || $placement === SocialPlacement::Reel;

        return $this->publishInstagramContainer($account, $igUserId, $first, [
            'caption' => $post->body,
            'media_type' => $isVideo ? 'REELS' : 'IMAGE',
            ...($isVideo ? ['share_to_feed' => 'true'] : []),
        ], $token);
    }

    /**
     * @param  Collection<int, SocialPostMedia>  $media
     * @return array{ok: bool, external_id: ?string, error: ?string}
     */
    private function publishInstagramCarousel(SocialAccount $account, SocialPost $post, string $igUserId, Collection $media, string $token): array
    {
        $children = [];
        foreach ($media as $item) {
            $container = $this->createInstagramContainer($account, $igUserId, $item, [
                'is_carousel_item' => 'true',
                'media_type' => $item->kind === 'video' ? 'VIDEO' : 'IMAGE',
            ], $token);
            if (! $container['ok'] || ! filled($container['id'])) {
                return $this->fail($container['error'] ?? 'Instagram did not return a media container.');
            }
            $children[] = $container['id'];
        }

        $parent = Http::timeout((int) config('services.social.upload_timeout', 60))
            ->connectTimeout(10)
            ->acceptJson()
            ->asForm()
            ->post(
                FacebookGraph::url($igUserId.'/media'),
                FacebookGraph::withToken([
                    'caption' => $post->body,
                    'media_type' => 'CAROUSEL',
                    'children' => implode(',', $children),
                ], $token),
            );

        if (! $parent->successful()) {
            return $this->fail($this->graphError($parent->json(), $parent->status()));
        }

        $creationId = data_get($parent->json(), 'id');
        if (! is_string($creationId) || $creationId === '') {
            return $this->fail('Instagram did not return a media container.');
        }

        $waitError = $this->waitForInstagramContainer($creationId, $token, $media->contains(fn (SocialPostMedia $item) => $item->kind === 'video'));
        if (is_string($waitError)) {
            return $this->fail($waitError);
        }

        return $this->publishInstagramCreation($igUserId, $creationId, $token);
    }

    /**
     * @param  array<string, string>  $extra
     * @return array{ok: bool, external_id: ?string, error: ?string}
     */
    private function publishInstagramContainer(SocialAccount $account, string $igUserId, SocialPostMedia $media, array $extra, string $token): array
    {
        $container = $this->createInstagramContainer($account, $igUserId, $media, $extra, $token);
        if (! $container['ok'] || ! filled($container['id'])) {
            return $this->fail($container['error'] ?? 'Instagram did not return a media container.');
        }

        return $this->publishInstagramCreation($igUserId, $container['id'], $token);
    }

    /**
     * @param  array<string, string>  $extra
     * @return array{ok: bool, id: ?string, error: ?string}
     */
    private function createInstagramContainer(SocialAccount $account, string $igUserId, SocialPostMedia $media, array $extra, string $token): array
    {
        $disk = Storage::disk('public');
        if (! $disk->exists((string) $media->path)) {
            return ['ok' => false, 'id' => null, 'error' => 'Media file is missing.'];
        }

        $contents = $disk->get((string) $media->path);
        if ((! is_string($contents) || $contents === '') && $disk->exists((string) $media->path)) {
            $absolute = $disk->path((string) $media->path);
            if (is_file($absolute)) {
                $contents = (string) file_get_contents($absolute);
            }
        }
        if (! is_string($contents) || $contents === '') {
            return ['ok' => false, 'id' => null, 'error' => 'Media file is empty.'];
        }

        $isVideo = $media->kind === 'video' || in_array($extra['media_type'] ?? '', ['REELS', 'VIDEO'], true);
        if ($isVideo) {
            return $this->createInstagramResumableContainer($igUserId, $media, $contents, $extra, $token);
        }

        $jpeg = $this->instagramJpeg($media, $contents);
        if (! $jpeg['ok'] || ! is_string($jpeg['contents']) || $jpeg['contents'] === '') {
            return ['ok' => false, 'id' => null, 'error' => $jpeg['error'] ?? 'instagram_media_type'];
        }

        $imageUrl = $this->instagramPublicImageUrl($media, $jpeg['contents']);
        if (! filled($imageUrl)) {
            $hosted = $this->hostInstagramImageOnFacebook($account, $jpeg['contents'], $token);
            if (! $hosted['ok'] || ! filled($hosted['url'])) {
                return ['ok' => false, 'id' => null, 'error' => $hosted['error'] ?? 'instagram_media_fetch'];
            }
            $imageUrl = $hosted['url'];
        }

        $payload = $extra;
        unset($payload['upload_type']);
        $payload['image_url'] = $imageUrl;

        $container = Http::timeout((int) config('services.social.upload_timeout', 60))
            ->connectTimeout(10)
            ->acceptJson()
            ->asForm()
            ->post(
                FacebookGraph::url($igUserId.'/media'),
                FacebookGraph::withToken($payload, $token),
            );

        if (! $container->successful()) {
            return ['ok' => false, 'id' => null, 'error' => $this->graphError($container->json(), $container->status())];
        }

        $creationId = data_get($container->json(), 'id');
        if (! is_string($creationId) || $creationId === '') {
            return ['ok' => false, 'id' => null, 'error' => 'Instagram did not return a media container.'];
        }

        $waitError = $this->waitForInstagramContainer($creationId, $token, false);
        if (is_string($waitError)) {
            return ['ok' => false, 'id' => null, 'error' => $waitError];
        }

        return ['ok' => true, 'id' => $creationId, 'error' => null];
    }

    /**
     * @param  array<string, string>  $extra
     * @return array{ok: bool, id: ?string, error: ?string}
     */
    private function createInstagramResumableContainer(string $igUserId, SocialPostMedia $media, string $contents, array $extra, string $token): array
    {
        $payload = $extra;
        $payload['upload_type'] = 'resumable';

        $container = Http::timeout((int) config('services.social.upload_timeout', 60))
            ->connectTimeout(10)
            ->acceptJson()
            ->asForm()
            ->post(
                FacebookGraph::url($igUserId.'/media'),
                FacebookGraph::withToken($payload, $token),
            );

        if (! $container->successful()) {
            return ['ok' => false, 'id' => null, 'error' => $this->graphError($container->json(), $container->status())];
        }

        $creationId = data_get($container->json(), 'id');
        if (! is_string($creationId) || $creationId === '') {
            return ['ok' => false, 'id' => null, 'error' => 'Instagram did not return a media container.'];
        }

        $upload = $this->uploadInstagramResumable($creationId, $contents, $media, $token);
        if (! $upload['ok']) {
            return $upload;
        }

        $waitError = $this->waitForInstagramContainer($creationId, $token, true);
        if (is_string($waitError)) {
            return ['ok' => false, 'id' => null, 'error' => $waitError];
        }

        return ['ok' => true, 'id' => $creationId, 'error' => null];
    }

    /**
     * @return array{ok: bool, contents: ?string, error: ?string}
     */
    private function instagramJpeg(SocialPostMedia $media, string $contents): array
    {
        $mime = strtolower((string) ($media->mime ?: ''));
        $name = strtolower((string) $media->original_name);
        $alreadyJpeg = str_contains($mime, 'jpeg') || str_contains($mime, 'jpg')
            || str_ends_with($name, '.jpg') || str_ends_with($name, '.jpeg');

        if ($alreadyJpeg) {
            return ['ok' => true, 'contents' => $contents, 'error' => null];
        }

        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            return ['ok' => false, 'contents' => null, 'error' => 'instagram_media_type'];
        }

        $image = @imagecreatefromstring($contents);
        if ($image === false) {
            return ['ok' => false, 'contents' => null, 'error' => 'instagram_media_type'];
        }

        $trueColor = imagecreatetruecolor(imagesx($image), imagesy($image));
        if ($trueColor === false) {
            imagedestroy($image);

            return ['ok' => false, 'contents' => null, 'error' => 'instagram_media_type'];
        }

        $white = imagecolorallocate($trueColor, 255, 255, 255);
        if ($white !== false) {
            imagefilledrectangle($trueColor, 0, 0, imagesx($image), imagesy($image), $white);
        }
        imagecopy($trueColor, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        imagedestroy($image);

        ob_start();
        $wrote = imagejpeg($trueColor, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($trueColor);

        if (! $wrote || $jpeg === '') {
            return ['ok' => false, 'contents' => null, 'error' => 'instagram_media_type'];
        }

        return ['ok' => true, 'contents' => $jpeg, 'error' => null];
    }

    private function instagramPublicImageUrl(SocialPostMedia $media, string $jpeg): ?string
    {
        $path = (string) $media->path;
        $jpegPath = preg_replace('/\.[^.]+$/', '', $path).'-ig.jpg';
        if (! is_string($jpegPath) || $jpegPath === '') {
            $jpegPath = $path.'-ig.jpg';
        }
        Storage::disk('public')->put($jpegPath, $jpeg);

        $relative = Storage::disk('public')->url($jpegPath);
        $url = str_starts_with($relative, 'http')
            ? $relative
            : rtrim((string) config('app.url'), '/').$relative;

        return $this->instagramCanFetch($url) ? $url : null;
    }

    private function instagramCanFetch(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return false;
        }
        if (str_ends_with($host, '.local') || str_ends_with($host, '.localhost')) {
            return false;
        }
        if (preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[0-1])\.)/', $host) === 1) {
            return false;
        }

        return true;
    }

    /**
     * @return array{ok: bool, url: ?string, error: ?string}
     */
    private function hostInstagramImageOnFacebook(SocialAccount $account, string $jpeg, string $token): array
    {
        $pageId = filled($account->facebook_page_id) ? (string) $account->facebook_page_id : null;
        $pageToken = $token;

        if (! filled($pageId) || $account->platform !== SocialPlatform::Facebook) {
            $facebook = SocialAccount::query()
                ->where('platform', SocialPlatform::Facebook)
                ->where('is_active', true)
                ->where(function ($query) use ($account, $pageId) {
                    $query->where('page_id', $pageId ?: $account->facebook_page_id)
                        ->orWhere('facebook_page_id', $pageId ?: $account->facebook_page_id)
                        ->orWhere('page_id', $account->page_id);
                })
                ->whereNotNull('access_token')
                ->first();
            if ($facebook) {
                $pageId = (string) ($facebook->page_id ?: $facebook->facebook_page_id);
                $pageToken = (string) $facebook->access_token;
            }
        }

        if (! filled($pageId)) {
            $pageId = (string) $account->facebook_page_id;
        }
        if (! filled($pageId)) {
            return ['ok' => false, 'url' => null, 'error' => 'instagram_media_fetch'];
        }

        $upload = Http::timeout((int) config('services.social.upload_timeout', 60))
            ->connectTimeout(10)
            ->acceptJson()
            ->attach('source', $jpeg, 'photo.jpg')
            ->post(
                FacebookGraph::url($pageId.'/photos'),
                FacebookGraph::withToken(['published' => 'false'], $pageToken),
            );

        if (! $upload->successful()) {
            return ['ok' => false, 'url' => null, 'error' => $this->graphError($upload->json(), $upload->status())];
        }

        $photoId = data_get($upload->json(), 'id');
        if (! is_string($photoId) || $photoId === '') {
            return ['ok' => false, 'url' => null, 'error' => 'instagram_media_fetch'];
        }

        $meta = Http::timeout((int) config('services.social.timeout', 20))
            ->connectTimeout(3)
            ->acceptJson()
            ->get(
                FacebookGraph::url($photoId),
                FacebookGraph::withToken(['fields' => 'images'], $pageToken),
            );

        $images = data_get($meta->json(), 'images');
        if (! is_array($images) || $images === []) {
            return ['ok' => false, 'url' => null, 'error' => 'instagram_media_fetch'];
        }

        usort($images, fn ($a, $b) => (int) data_get($b, 'width') <=> (int) data_get($a, 'width'));
        $source = data_get($images[0], 'source');
        if (! is_string($source) || $source === '') {
            return ['ok' => false, 'url' => null, 'error' => 'instagram_media_fetch'];
        }

        return ['ok' => true, 'url' => $source, 'error' => null];
    }

    /**
     * @return array{ok: bool, id: ?string, error: ?string}
     */
    private function uploadInstagramResumable(string $containerId, string $contents, SocialPostMedia $media, string $token): array
    {
        $response = Http::timeout((int) config('services.social.upload_timeout', 60))
            ->connectTimeout(10)
            ->acceptJson()
            ->withHeaders([
                'Authorization' => 'OAuth '.$token,
                'offset' => '0',
                'file_size' => (string) strlen($contents),
            ])
            ->withBody($contents, $media->mime ?: 'application/octet-stream')
            ->post(FacebookGraph::ruploadUrl($containerId));

        if ($response->successful() || data_get($response->json(), 'success') === true) {
            return ['ok' => true, 'id' => $containerId, 'error' => null];
        }

        return ['ok' => false, 'id' => null, 'error' => $this->graphError($response->json(), $response->status())];
    }

    /**
     * @return array{ok: bool, external_id: ?string, error: ?string}
     */
    private function publishInstagramCreation(string $igUserId, string $creationId, string $token): array
    {
        $publish = Http::timeout((int) config('services.social.timeout', 20))
            ->connectTimeout(3)
            ->acceptJson()
            ->asForm()
            ->post(
                FacebookGraph::url($igUserId.'/media_publish'),
                FacebookGraph::withToken([
                    'creation_id' => $creationId,
                ], $token),
            );

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
     * @return array{ok: bool, id: ?string, external_id: ?string, error: ?string}
     */
    private function uploadFacebookFile(string $pageId, string $token, SocialPostMedia $media, bool $unpublished, ?string $caption = null): array
    {
        $disk = Storage::disk('public');
        if (! $disk->exists((string) $media->path)) {
            return ['ok' => false, 'id' => null, 'external_id' => null, 'error' => 'Media file is missing.'];
        }

        $contents = $disk->get((string) $media->path);
        if (! is_string($contents) || $contents === '') {
            return ['ok' => false, 'id' => null, 'external_id' => null, 'error' => 'Media file is empty.'];
        }

        $isVideo = $media->kind === 'video';
        $filename = $media->original_name ?: basename((string) $media->path);
        $fields = [];
        if ($unpublished) {
            $fields['published'] = 'false';
        } elseif (filled($caption)) {
            $fields[$isVideo ? 'description' : 'message'] = $caption;
        }

        $response = Http::timeout((int) config('services.social.upload_timeout', 60))
            ->connectTimeout(10)
            ->acceptJson()
            ->attach('source', $contents, $filename)
            ->post(
                FacebookGraph::url($isVideo ? $pageId.'/videos' : $pageId.'/photos'),
                FacebookGraph::withToken($fields, $token),
            );

        if (! $response->successful()) {
            return ['ok' => false, 'id' => null, 'external_id' => null, 'error' => $this->graphError($response->json(), $response->status())];
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];
        $id = isset($json['id']) ? (string) $json['id'] : null;
        $externalId = isset($json['post_id']) && is_string($json['post_id']) && $json['post_id'] !== ''
            ? (string) $json['post_id']
            : $id;

        if ($id && $externalId === $id && ! $isVideo && ! $unpublished) {
            $storyId = $this->facebookPageStoryId($id, $token);
            if (is_string($storyId) && $storyId !== '') {
                $externalId = $storyId;
            } elseif ($pageId !== '') {
                $externalId = $pageId.'_'.$id;
            }
        }

        return [
            'ok' => true,
            'id' => $id,
            'external_id' => $externalId,
            'error' => null,
        ];
    }

    private function waitForInstagramContainer(string $creationId, string $token, bool $video = false): ?string
    {
        $attempts = $video ? 40 : 16;
        $delayUs = $video ? 2_000_000 : 500_000;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($attempt > 0) {
                usleep($delayUs);
            }

            $status = Http::timeout(10)
                ->connectTimeout(3)
                ->acceptJson()
                ->get(
                    FacebookGraph::url($creationId),
                    FacebookGraph::withToken(['fields' => 'status_code,status'], $token),
                );

            $code = data_get($status->json(), 'status_code');
            if ($code === 'FINISHED') {
                return null;
            }

            if (in_array($code, ['ERROR', 'EXPIRED'], true)) {
                $message = data_get($status->json(), 'status');
                if (is_string($message) && $message !== '') {
                    return $this->graphError(['error' => ['message' => $message]], 400);
                }

                return 'instagram_media_fetch';
            }
        }

        return 'instagram_media_processing';
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

    private function graphError(mixed $json, int $status): string
    {
        $message = FacebookGraph::errorMessage($json, $status);
        if (str_contains($message, 'pages_manage_engagement')) {
            return 'missing_pages_manage_engagement';
        }
        if (str_contains($message, 'pages_messaging')) {
            return 'missing_pages_messaging';
        }
        if (str_contains($message, 'Cannot call API for app')) {
            return 'facebook_app_user_mismatch';
        }
        $lower = strtolower($message);
        if (str_contains($lower, 'image_url is required') || str_contains($lower, 'parameter image_url')) {
            return 'instagram_media_type';
        }
        if (str_contains($lower, 'only photo or video')) {
            return 'instagram_media_type';
        }
        if (str_contains($message, '2207076')
            || str_contains($lower, 'media upload has failed')
            || str_contains($lower, 'media download has failed')) {
            return 'instagram_media_fetch';
        }

        return $message;
    }

    /**
     * @param  \Illuminate\Http\Client\Response  $response
     */
    private function graphObjectGone($response): bool
    {
        if ($response->notFound()) {
            return true;
        }

        $code = data_get($response->json(), 'error.code');
        $subcode = data_get($response->json(), 'error.error_subcode');
        $message = strtolower((string) data_get($response->json(), 'error.message'));

        return in_array($code, [12, 803], true)
            || $subcode === 33
            || str_contains($message, 'does not exist')
            || str_contains($message, 'has been deleted')
            || str_contains($message, 'unsupported delete request');
    }

    private function facebookPageStoryId(string $photoId, string $token): ?string
    {
        $meta = Http::timeout((int) config('services.social.timeout', 20))
            ->connectTimeout(3)
            ->acceptJson()
            ->get(
                FacebookGraph::url($photoId),
                FacebookGraph::withToken(['fields' => 'page_story_id,post_id'], $token),
            );

        $storyId = data_get($meta->json(), 'page_story_id') ?: data_get($meta->json(), 'post_id');

        return is_string($storyId) && $storyId !== '' ? $storyId : null;
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
