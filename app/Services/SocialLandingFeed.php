<?php

namespace App\Services;

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SocialLandingFeed
{
    private bool $tokenRefreshAttempted = false;

    private bool $skipCache = false;

    private ?int $lastGraphStatus = null;

    private ?string $lastGraphMessage = null;

    /**
     * @return array{profile: array<string, mixed>|null, posts: list<array{id: string, caption: string|null, media_type: string, media_url: string|null, preview_url: string|null, permalink: string|null, timestamp: string|null}>}
     */
    public function instagram(int $limit = 200): array
    {
        return $this->rememberFeed('instagram', $limit, fn () => $this->buildInstagram($limit));
    }

    /**
     * @return list<array{id: string, caption: string|null, media_type: string, media_url: string|null, preview_url: string|null, permalink: string|null, timestamp: string|null}>
     */
    public function instagramPosts(int $limit = 200): array
    {
        return $this->instagram($limit)['posts'];
    }

    /**
     * @return array{profile: array<string, mixed>|null, posts: list<array{id: string, caption: string|null, media_type: string, media_url: string|null, preview_url: string|null, permalink: string|null, timestamp: string|null}>}
     */
    public function facebook(int $limit = 200): array
    {
        return $this->rememberFeed('facebook', $limit, fn () => $this->buildFacebook($limit));
    }

    /**
     * @param  callable(): array{profile: array<string, mixed>|null, posts: list<array{id: string, caption: string|null, media_type: string, media_url: string|null, preview_url: string|null, permalink: string|null, timestamp: string|null}>}  $builder
     * @return array{profile: array<string, mixed>|null, posts: list<array{id: string, caption: string|null, media_type: string, media_url: string|null, preview_url: string|null, permalink: string|null, timestamp: string|null}>}
     */
    private function rememberFeed(string $platform, int $limit, callable $builder): array
    {
        $key = $this->cacheKey($platform, $limit);
        $cached = Cache::get($key);
        if (is_array($cached) && isset($cached['posts']) && is_array($cached['posts'])) {
            /** @var array{profile: array<string, mixed>|null, posts: list<array{id: string, caption: string|null, media_type: string, media_url: string|null, preview_url: string|null, permalink: string|null, timestamp: string|null}>} $cached */
            return $cached;
        }

        $this->skipCache = false;
        $data = $builder();
        if (! $this->skipCache) {
            Cache::put($key, $data, 600);
        }

        return $data;
    }

    /**
     * @return array{profile: array<string, mixed>|null, posts: list<array{id: string, caption: string|null, media_type: string, media_url: string|null, preview_url: string|null, permalink: string|null, timestamp: string|null}>}
     */
    private function buildFacebook(int $limit): array
    {
        $account = $this->facebookAccount();
        if (! $account || ! $account->hasToken() || ! filled($account->page_id)) {
            return ['profile' => null, 'posts' => []];
        }

        $profile = $this->fetchFacebookProfile($account);
        $posts = $this->fetchFacebookPosts($account, $limit);
        if ($posts === [] && $this->shouldRefreshTokens()) {
            $account = $this->refreshAccountTokens() ?? $account->fresh();
            if ($account && $account->hasToken()) {
                $profile = $this->fetchFacebookProfile($account);
                $posts = $this->fetchFacebookPosts($account, $limit);
            }
        }

        if ($posts === [] && $this->lastGraphStatus !== null && (
            $this->isExpiredTokenError($this->lastGraphMessage)
            || FacebookGraph::isNewPagesExperienceMessage($this->lastGraphMessage)
        )) {
            $this->skipCache = true;
        }

        return [
            'profile' => $profile,
            'posts' => $posts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchFacebookProfile(SocialAccount $account): array
    {
        $payload = $this->graphGet((string) $account->page_id, [
            'fields' => 'name,username,about,fan_count,followers_count,link,picture.type(large){url}',
        ], (string) $account->access_token) ?? [];

        $username = (string) ($payload['username'] ?? $account->handle ?? '');

        return [
            'username' => $username !== '' ? $username : (string) $account->name,
            'name' => $this->nullableString($payload['name'] ?? $account->name),
            'biography' => $this->nullableString($payload['about'] ?? null),
            'profile_picture_url' => $this->nullableString(data_get($payload, 'picture.data.url')),
            'followers_count' => $this->nullableInt($payload['followers_count'] ?? $payload['fan_count'] ?? null),
            'follows_count' => $this->nullableInt($payload['fan_count'] ?? null),
            'media_count' => null,
            'permalink' => $this->nullableString($payload['link'] ?? null),
        ];
    }

    /**
     * @return list<array{id: string, caption: string|null, media_type: string, media_url: string|null, preview_url: string|null, permalink: string|null, timestamp: string|null}>
     */
    private function fetchFacebookPosts(SocialAccount $account, int $limit): array
    {
        $postFields = 'id,message,full_picture,permalink_url,created_time,status_type,attachments{media_type,type,url,media,subattachments,unshimmed_url,target}';
        foreach (['published_posts', 'posts'] as $edge) {
            $items = $this->paginateFacebookEdge($account, $edge, $postFields, $limit, fn (array $row) => $this->mapFacebookPost($row));
            if ($items !== null) {
                return $items;
            }
        }

        $photos = $this->paginateFacebookEdge(
            $account,
            'photos',
            'id,name,link,created_time,images',
            $limit,
            fn (array $row) => $this->mapFacebookPhoto($row),
        ) ?? [];
        $videos = $this->paginateFacebookEdge(
            $account,
            'videos',
            'id,description,permalink_url,created_time,source,picture',
            $limit,
            fn (array $row) => $this->mapFacebookVideo($row),
        ) ?? [];

        $merged = array_merge($photos, $videos);
        usort($merged, fn (array $a, array $b) => strcmp((string) ($b['timestamp'] ?? ''), (string) ($a['timestamp'] ?? '')));

        return array_slice($merged, 0, $limit);
    }

    /**
     * @param  callable(array<string, mixed>): ?array{id: string, caption: string|null, media_type: string, media_url: string|null, preview_url: string|null, permalink: string|null, timestamp: string|null}  $mapper
     * @return list<array{id: string, caption: string|null, media_type: string, media_url: string|null, preview_url: string|null, permalink: string|null, timestamp: string|null}>|null
     */
    private function paginateFacebookEdge(SocialAccount $account, string $edge, string $fields, int $limit, callable $mapper): ?array
    {
        $items = [];
        $after = null;
        $fetched = false;

        do {
            $query = [
                'fields' => $fields,
                'limit' => min(25, max(1, $limit - count($items))),
            ];
            if (is_string($after) && $after !== '') {
                $query['after'] = $after;
            }

            $payload = $this->graphGet($account->page_id.'/'.$edge, $query, (string) $account->access_token);
            if ($payload === null) {
                return $fetched ? $items : null;
            }
            $fetched = true;

            foreach (data_get($payload, 'data') ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $mapped = $mapper($row);
                if ($mapped !== null) {
                    $items[] = $mapped;
                }
                if (count($items) >= $limit) {
                    return $items;
                }
            }

            $after = data_get($payload, 'paging.cursors.after');
            $after = is_string($after) && filled(data_get($payload, 'paging.next')) ? $after : null;
        } while ($after !== null);

        return $items;
    }

    private function facebookAccount(): ?SocialAccount
    {
        $query = $this->connectedAccounts(SocialPlatform::Facebook)
            ->whereNotNull('page_id');

        $pageId = trim((string) config('services.social.landing_facebook_page_id', ''));
        if ($pageId !== '') {
            $matched = (clone $query)->where('page_id', $pageId)->first();
            if ($matched) {
                return $matched;
            }
        }

        $instagram = $this->instagramAccount();
        if ($instagram && filled($instagram->facebook_page_id)) {
            $linked = (clone $query)->where('page_id', $instagram->facebook_page_id)->first();
            if ($linked) {
                return $linked;
            }
        }

        $handle = strtolower(trim((string) config('services.social.landing_facebook_handle', '')));
        if ($handle !== '') {
            $matched = $this->firstMatchingHandle($query, $handle);
            if ($matched) {
                return $matched;
            }
        }

        return $query->orderBy('name')->first();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: string, caption: string|null, media_type: string, media_url: string|null, preview_url: string|null, permalink: string|null, timestamp: string|null}|null
     */
    private function mapFacebookPost(array $row): ?array
    {
        $id = $row['id'] ?? null;
        if (! is_string($id) || $id === '') {
            return null;
        }

        $attachment = data_get($row, 'attachments.data.0');
        $attachment = is_array($attachment) ? $attachment : [];
        $child = data_get($attachment, 'subattachments.data.0');
        $child = is_array($child) ? $child : [];

        $typeHint = strtolower((string) ($attachment['media_type'] ?? $attachment['type'] ?? $row['status_type'] ?? ''));
        $isVideo = str_contains($typeHint, 'video');
        $isAlbum = str_contains($typeHint, 'album') || str_contains($typeHint, 'carousel') || $child !== [];

        $videoUrl = $this->nullableString(data_get($attachment, 'media.source'))
            ?? $this->nullableString(data_get($child, 'media.source'));
        $imageUrl = $this->nullableString($row['full_picture'] ?? null)
            ?? $this->nullableString(data_get($attachment, 'media.image.src'))
            ?? $this->nullableString(data_get($child, 'media.image.src'));

        if ($videoUrl === null && $imageUrl === null) {
            return null;
        }

        $mediaType = $isVideo ? 'VIDEO' : ($isAlbum ? 'CAROUSEL_ALBUM' : 'IMAGE');

        return [
            'id' => $id,
            'caption' => $this->nullableString($row['message'] ?? null),
            'media_type' => $mediaType,
            'media_url' => $videoUrl ?? $imageUrl,
            'preview_url' => $imageUrl ?? $videoUrl,
            'permalink' => $this->nullableString($row['permalink_url'] ?? null),
            'timestamp' => $this->nullableString($row['created_time'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: string, caption: string|null, media_type: string, media_url: string|null, preview_url: string|null, permalink: string|null, timestamp: string|null}|null
     */
    private function mapFacebookPhoto(array $row): ?array
    {
        $id = $row['id'] ?? null;
        if (! is_string($id) || $id === '') {
            return null;
        }

        $images = $row['images'] ?? [];
        $images = is_array($images) ? $images : [];
        usort($images, fn ($a, $b) => (int) data_get($b, 'width') <=> (int) data_get($a, 'width'));
        $source = $this->nullableString(data_get($images, '0.source'));
        if ($source === null) {
            return null;
        }

        return [
            'id' => $id,
            'caption' => $this->nullableString($row['name'] ?? null),
            'media_type' => 'IMAGE',
            'media_url' => $source,
            'preview_url' => $source,
            'permalink' => $this->nullableString($row['link'] ?? null),
            'timestamp' => $this->nullableString($row['created_time'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: string, caption: string|null, media_type: string, media_url: string|null, preview_url: string|null, permalink: string|null, timestamp: string|null}|null
     */
    private function mapFacebookVideo(array $row): ?array
    {
        $id = $row['id'] ?? null;
        if (! is_string($id) || $id === '') {
            return null;
        }

        $videoUrl = $this->nullableString($row['source'] ?? null);
        $imageUrl = $this->nullableString($row['picture'] ?? null);
        if ($videoUrl === null && $imageUrl === null) {
            return null;
        }

        return [
            'id' => $id,
            'caption' => $this->nullableString($row['description'] ?? null),
            'media_type' => 'VIDEO',
            'media_url' => $videoUrl ?? $imageUrl,
            'preview_url' => $imageUrl ?? $videoUrl,
            'permalink' => $this->nullableString($row['permalink_url'] ?? null),
            'timestamp' => $this->nullableString($row['created_time'] ?? null),
        ];
    }

    /**
     * @return array{profile: array<string, mixed>|null, posts: list<array{id: string, caption: string|null, media_type: string, media_url: string|null, preview_url: string|null, permalink: string|null, timestamp: string|null}>}
     */
    private function buildInstagram(int $limit): array
    {
        $account = $this->instagramAccount();
        if (! $account || ! $account->hasToken() || ! filled($account->page_id)) {
            return ['profile' => null, 'posts' => []];
        }

        $igUserId = $this->instagramGraphUserId($account);
        $profile = $this->fetchProfile($account, $igUserId);
        $posts = $this->fetchInstagramPosts($account, $igUserId, $limit);
        if ($posts === [] && $this->shouldRefreshTokens()) {
            $account = $this->refreshAccountTokens() ?? $account->fresh();
            if ($account && $account->hasToken()) {
                $igUserId = $this->instagramGraphUserId($account);
                $profile = $this->fetchProfile($account, $igUserId);
                $posts = $this->fetchInstagramPosts($account, $igUserId, $limit);
            }
        }

        if ($posts === [] && $this->lastGraphStatus !== null && (
            $this->isExpiredTokenError($this->lastGraphMessage)
            || FacebookGraph::isNewPagesExperienceMessage($this->lastGraphMessage)
        )) {
            $this->skipCache = true;
        }

        return [
            'profile' => $profile,
            'posts' => $posts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchProfile(SocialAccount $account, ?string $igUserId = null): array
    {
        $payload = $this->graphGet((string) ($igUserId ?: $account->page_id), [
            'fields' => 'username,name,biography,profile_picture_url,followers_count,follows_count,media_count',
        ], (string) $account->access_token) ?? [];

        $username = (string) ($payload['username'] ?? $account->handle ?? '');

        return [
            'username' => $username,
            'name' => $this->nullableString($payload['name'] ?? $account->name),
            'biography' => $this->nullableString($payload['biography'] ?? null),
            'profile_picture_url' => $this->nullableString($payload['profile_picture_url'] ?? null),
            'followers_count' => $this->nullableInt($payload['followers_count'] ?? null),
            'follows_count' => $this->nullableInt($payload['follows_count'] ?? null),
            'media_count' => $this->nullableInt($payload['media_count'] ?? null),
            'permalink' => $username !== '' ? 'https://www.instagram.com/'.$username.'/' : null,
        ];
    }

    /**
     * @return list<array{id: string, caption: string|null, media_type: string, media_url: string|null, preview_url: string|null, permalink: string|null, timestamp: string|null}>
     */
    private function fetchInstagramPosts(SocialAccount $account, string $igUserId, int $limit): array
    {
        $items = [];
        $after = null;

        do {
            $query = [
                'fields' => 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,children{media_url,media_type,thumbnail_url}',
                'limit' => min(25, $limit - count($items)),
            ];
            if (is_string($after) && $after !== '') {
                $query['after'] = $after;
            }

            $payload = $this->graphGet($igUserId.'/media', $query, (string) $account->access_token);
            if ($payload === null) {
                if ($items === [] && FacebookGraph::isNewPagesExperienceMessage($this->lastGraphMessage)) {
                    $repaired = $this->repairInstagramUserId($account, $igUserId);
                    if ($repaired !== $igUserId) {
                        return $this->fetchInstagramPosts($account, $repaired, $limit);
                    }
                }
                break;
            }

            foreach (data_get($payload, 'data') ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $mapped = $this->mapMedia($row);
                if ($mapped !== null) {
                    $items[] = $mapped;
                }
                if (count($items) >= $limit) {
                    return $items;
                }
            }

            $after = data_get($payload, 'paging.cursors.after');
            $after = is_string($after) && filled(data_get($payload, 'paging.next')) ? $after : null;
        } while ($after !== null);

        return $items;
    }

    private function instagramAccount(): ?SocialAccount
    {
        $query = $this->connectedAccounts(SocialPlatform::Instagram);
        $handle = strtolower(trim((string) config('services.social.landing_instagram_handle', '')));

        if ($handle !== '') {
            $matched = $this->firstMatchingHandle($query, $handle);
            if ($matched) {
                return $matched;
            }
        }

        return $query
            ->orderByRaw("CASE WHEN lower(handle) like '%homeofcreativity%' OR lower(name) like '%homeofcreativity%' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->first();
    }

    private function instagramGraphUserId(SocialAccount $account): string
    {
        $current = (string) $account->page_id;
        $facebookPageId = (string) ($account->facebook_page_id ?: '');
        if ($current !== '' && ($facebookPageId === '' || $current !== $facebookPageId)) {
            return $current;
        }

        return $this->repairInstagramUserId($account, $current);
    }

    private function repairInstagramUserId(SocialAccount $account, string $currentId): string
    {
        $lookupId = (string) ($account->facebook_page_id ?: $currentId);
        $resolved = FacebookGraph::instagramBusinessAccountId($lookupId, (string) $account->access_token);
        if (! is_string($resolved) || $resolved === '' || $resolved === $currentId) {
            return $currentId;
        }

        $account->forceFill(['page_id' => $resolved])->save();

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: string, caption: string|null, media_type: string, media_url: string|null, preview_url: string|null, permalink: string|null, timestamp: string|null}|null
     */
    private function mapMedia(array $row): ?array
    {
        $id = $row['id'] ?? null;
        if (! is_string($id) || $id === '') {
            return null;
        }

        $type = (string) ($row['media_type'] ?? 'IMAGE');
        $mediaUrl = $this->nullableString($row['media_url'] ?? null);
        $thumb = $this->nullableString($row['thumbnail_url'] ?? null);
        if ($mediaUrl === null && $thumb === null) {
            $child = data_get($row, 'children.data.0');
            if (is_array($child)) {
                $mediaUrl = $this->nullableString($child['media_url'] ?? null);
                $thumb = $this->nullableString($child['thumbnail_url'] ?? null);
            }
        }

        $preview = $thumb ?? $mediaUrl;

        return [
            'id' => $id,
            'caption' => $this->nullableString($row['caption'] ?? null),
            'media_type' => $type,
            'media_url' => $mediaUrl,
            'preview_url' => $preview,
            'permalink' => $this->nullableString($row['permalink'] ?? null),
            'timestamp' => $this->nullableString($row['timestamp'] ?? null),
        ];
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>|null
     */
    private function graphGet(string $path, array $query, string $token): ?array
    {
        try {
            $response = Http::timeout((int) config('services.social.timeout', 20))
                ->connectTimeout((int) config('services.social.connect_timeout', 10))
                ->retry(2, 200)
                ->acceptJson()
                ->get(FacebookGraph::url($path), FacebookGraph::withToken($query, $token));
        } catch (ConnectionException|Throwable $exception) {
            $this->lastGraphStatus = null;
            $this->lastGraphMessage = $exception instanceof ConnectionException ? 'connection' : $exception::class;
            Log::warning('Social landing feed failed.', [
                'path' => $path,
                'error' => $this->lastGraphMessage,
            ]);

            return null;
        }

        if (! $response->successful()) {
            $this->lastGraphStatus = $response->status();
            $this->lastGraphMessage = FacebookGraph::errorMessage($response->json(), $response->status());
            Log::warning('Social landing feed rejected.', [
                'path' => $path,
                'status' => $response->status(),
                'message' => $this->lastGraphMessage,
            ]);

            return null;
        }

        $this->lastGraphStatus = null;
        $this->lastGraphMessage = null;

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        return $json;
    }

    private function shouldRefreshTokens(): bool
    {
        return ! $this->tokenRefreshAttempted
            && $this->isExpiredTokenError($this->lastGraphMessage);
    }

    private function refreshAccountTokens(): ?SocialAccount
    {
        $this->tokenRefreshAttempted = true;
        $sync = app(SocialAccountSync::class);
        if (! $sync->configured()) {
            return null;
        }

        $sync->syncFromFacebook();
        Cache::forget($this->cacheKey('instagram', 200));
        Cache::forget($this->cacheKey('facebook', 200));
        $this->lastGraphStatus = null;
        $this->lastGraphMessage = null;

        return null;
    }

    private function isExpiredTokenError(?string $message): bool
    {
        if (! is_string($message) || $message === '') {
            return false;
        }

        $normalized = strtolower($message);

        return str_contains($normalized, 'session has expired')
            || str_contains($normalized, 'error validating access token')
            || str_contains($normalized, 'access token has expired');
    }

    private function nullableString(mixed $value): ?string
    {
        return filled($value) && is_string($value) ? $value : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function cacheKey(string $platform, int $limit): string
    {
        return "social.landing.{$platform}.v2.{$limit}";
    }

    /**
     * @return Builder<SocialAccount>
     */
    private function connectedAccounts(SocialPlatform $platform): Builder
    {
        return SocialAccount::query()
            ->where('platform', $platform)
            ->where('connection_status', SocialAccountStatus::Connected)
            ->where('is_active', true)
            ->whereNotNull('access_token');
    }

    /**
     * @param  Builder<SocialAccount>  $query
     */
    private function firstMatchingHandle(Builder $query, string $handle): ?SocialAccount
    {
        return (clone $query)
            ->where(function ($builder) use ($handle): void {
                $builder->whereRaw('lower(handle) = ?', [$handle])
                    ->orWhereRaw('lower(name) like ?', ['%'.$handle.'%'])
                    ->orWhereRaw('lower(handle) like ?', ['%'.$handle.'%']);
            })
            ->first();
    }
}
