<?php

namespace App\Services;

use App\Enums\SocialInboxKind;
use App\Enums\SocialPlatform;
use App\Models\SocialAccount;
use App\Models\SocialInboxItem;
use App\Models\SocialPostAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SocialInboxSync
{
    public ?string $lastError = null;

    public function __construct(private SocialPublisher $publisher) {}

    public function syncAll(): int
    {
        $this->lastError = null;
        $imported = 0;

        SocialAccount::query()->active()->each(function (SocialAccount $account) use (&$imported): void {
            $imported += $this->syncAccount($account);
        });

        return $imported;
    }

    public function syncAccount(SocialAccount $account): int
    {
        if (! $account->is_active || ! $account->hasToken() || ! filled($account->page_id)) {
            return 0;
        }

        if (! in_array($account->platform, [SocialPlatform::Facebook, SocialPlatform::Instagram, SocialPlatform::Threads], true)) {
            return 0;
        }

        $imported = 0;
        if ($account->platform === SocialPlatform::Threads) {
            $imported += $this->importThreadsReplies($account);
            $imported += $this->importCommentsFromPublishedTargets($account);
            $this->pruneDeletedComments($account);

            return $imported;
        }

        if ($account->platform === SocialPlatform::Instagram) {
            $igUserId = $this->publisher->instagramGraphUserId($account);
            $imported += $this->importCommentsFromPath($account, $igUserId.'/media', [
                'fields' => 'id,caption,permalink,media_type,media_url,thumbnail_url,comments.limit(50){id,text,username,timestamp,from}',
                'limit' => '25',
            ]);
        } else {
            foreach ($this->facebookCommentEdges() as $edge => $fields) {
                $imported += $this->importCommentsFromPath($account, $account->page_id.'/'.$edge, [
                    'fields' => $fields,
                    'limit' => '25',
                ]);
            }
        }
        $imported += $this->importCommentsFromPublishedTargets($account);
        $imported += $this->importMessages($account);
        $this->pruneDeletedComments($account);

        return $imported;
    }

    private function importThreadsReplies(SocialAccount $account): int
    {
        $payload = $this->graphGet($account, $account->page_id.'/threads', [
            'fields' => 'id,text,permalink,timestamp,media_type,thumbnail_url,replies.limit(50){id,text,username,timestamp}',
            'limit' => '25',
        ]);

        if ($payload === null) {
            return 0;
        }

        $imported = 0;
        foreach (data_get($payload, 'data', []) as $post) {
            if (! is_array($post)) {
                continue;
            }
            $postId = data_get($post, 'id');
            if (! is_string($postId) || $postId === '') {
                continue;
            }

            $imported += $this->syncCommentsForPost(
                $account,
                $postId,
                data_get($post, 'replies.data', []),
                $this->sourceFromPost($account, $post),
            );
        }

        return $imported;
    }

    /**
     * @param  array<string, string>  $query
     */
    private function importCommentsFromPath(SocialAccount $account, string $path, array $query): int
    {
        $payload = $this->graphGet($account, $path, $query);

        if ($payload === null) {
            return 0;
        }

        $imported = 0;
        foreach (data_get($payload, 'data', []) as $post) {
            if (! is_array($post)) {
                continue;
            }
            $postId = data_get($post, 'id');
            if (! is_string($postId) || $postId === '') {
                continue;
            }

            $imported += $this->syncCommentsForPost(
                $account,
                $postId,
                data_get($post, 'comments.data', []),
                $this->sourceFromPost($account, $post),
            );
        }

        return $imported;
    }

    private function importCommentsFromPublishedTargets(SocialAccount $account): int
    {
        $imported = 0;

        SocialPostAccount::query()
            ->where('social_account_id', $account->id)
            ->whereNotNull('external_id')
            ->latest('id')
            ->limit(20)
            ->get()
            ->each(function (SocialPostAccount $target) use ($account, &$imported): void {
                $fields = $account->platform === SocialPlatform::Instagram
                    ? 'id,caption,permalink,media_type,media_url,thumbnail_url,comments.limit(50){id,text,username,timestamp,from}'
                    : ($account->platform === SocialPlatform::Threads
                        ? 'id,text,permalink,timestamp,media_type,thumbnail_url,replies.limit(50){id,text,username,timestamp}'
                        : 'id,message,permalink_url,full_picture,comments.limit(50){id,message,from,created_time}');
                $payload = $this->graphGet($account, (string) $target->external_id, [
                    'fields' => $fields,
                ]);

                if ($payload === null) {
                    return;
                }

                $imported += $this->syncCommentsForPost(
                    $account,
                    (string) $target->external_id,
                    $account->platform === SocialPlatform::Threads
                        ? data_get($payload, 'replies.data', [])
                        : data_get($payload, 'comments.data', []),
                    $this->sourceFromPost($account, is_array($payload) ? $payload : []),
                );
            });

        return $imported;
    }

    private function importMessages(SocialAccount $account): int
    {
        $query = [
            'fields' => 'id,updated_time,messages.limit(20){id,message,from,created_time}',
            'limit' => '25',
        ];
        $conversationPageId = (string) $account->page_id;
        if ($account->platform === SocialPlatform::Instagram) {
            $query['platform'] = 'instagram';
            if (filled($account->facebook_page_id)) {
                $conversationPageId = (string) $account->facebook_page_id;
            }
        }

        $payload = $this->graphGet($account, $conversationPageId.'/conversations', $query);

        if ($payload === null) {
            return 0;
        }

        $imported = 0;
        foreach (data_get($payload, 'data', []) as $conversation) {
            $conversationId = data_get($conversation, 'id');
            $conversationId = is_string($conversationId) ? $conversationId : null;
            foreach (data_get($conversation, 'messages.data', []) as $message) {
                $fromId = (string) data_get($message, 'from.id');
                $ownIds = array_values(array_filter([
                    (string) $account->page_id,
                    (string) ($account->facebook_page_id ?: ''),
                ]));
                if ($fromId !== '' && in_array($fromId, $ownIds, true)) {
                    continue;
                }

                $imported += $this->storeItem(
                    $account,
                    SocialInboxKind::Message,
                    data_get($message, 'id'),
                    data_get($message, 'message'),
                    data_get($message, 'from.name') ?? data_get($message, 'from.username'),
                    data_get($message, 'from.id'),
                    data_get($message, 'created_time'),
                    [
                        'source_external_id' => $conversationId,
                        'source_media_type' => 'conversation',
                    ],
                );
            }
        }

        return $imported;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function syncCommentsForPost(SocialAccount $account, string $postId, mixed $comments, array $source = []): int
    {
        $rows = is_array($comments) ? $comments : [];
        $imported = $this->storeComments($account, $rows, $postId, $source);
        $liveIds = [];
        foreach ($rows as $comment) {
            $id = data_get($comment, 'id');
            if (is_string($id) && $id !== '') {
                $liveIds[] = $id;
            }
        }

        $query = SocialInboxItem::query()
            ->where('social_account_id', $account->id)
            ->where('kind', SocialInboxKind::Comment)
            ->where('source_external_id', $postId);

        if ($liveIds !== []) {
            $query->whereNotIn('external_id', $liveIds);
        }

        $query->delete();

        return $imported;
    }

    /**
     * @param  list<array<string, mixed>>  $comments
     * @param  array<string, mixed>  $source
     */
    private function storeComments(SocialAccount $account, array $comments, string $sourceId, array $source = []): int
    {
        $imported = 0;
        $source['source_external_id'] = $source['source_external_id'] ?? $sourceId;
        foreach ($comments as $comment) {
            $imported += $this->storeItem(
                $account,
                SocialInboxKind::Comment,
                data_get($comment, 'id'),
                data_get($comment, 'message') ?? data_get($comment, 'text'),
                data_get($comment, 'from.name') ?? data_get($comment, 'username'),
                data_get($comment, 'from.id'),
                data_get($comment, 'created_time') ?? data_get($comment, 'timestamp'),
                $source,
            );
        }

        return $imported;
    }

    private function pruneDeletedComments(SocialAccount $account): void
    {
        SocialInboxItem::query()
            ->where('social_account_id', $account->id)
            ->where('kind', SocialInboxKind::Comment)
            ->get()
            ->each(function (SocialInboxItem $item) use ($account): void {
                if ($this->publisher->isLive($account, $item->external_id) === false) {
                    $item->delete();
                }
            });
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function storeItem(
        SocialAccount $account,
        SocialInboxKind $kind,
        mixed $externalId,
        mixed $body,
        mixed $authorName,
        mixed $authorHandle,
        mixed $occurredAt,
        array $source = [],
    ): int {
        if (! is_string($externalId) || $externalId === '') {
            return 0;
        }

        $item = SocialInboxItem::query()->firstOrNew([
            'social_account_id' => $account->id,
            'external_id' => $externalId,
        ]);

        $created = ! $item->exists;
        if ($created) {
            $item->fill([
                'kind' => $kind,
                'author_name' => is_string($authorName) && $authorName !== '' ? $authorName : 'Unknown',
                'author_handle' => is_string($authorHandle) ? $authorHandle : null,
                'body' => is_string($body) && $body !== '' ? $body : '—',
                'occurred_at' => is_string($occurredAt) ? $occurredAt : now(),
            ]);
        } elseif (is_string($authorHandle) && $authorHandle !== '' && ! filled($item->author_handle)) {
            $item->author_handle = $authorHandle;
        }

        foreach (['source_external_id', 'source_body', 'source_permalink', 'source_preview_url', 'source_media_type', 'social_post_id'] as $field) {
            if (array_key_exists($field, $source) && filled($source[$field])) {
                $item->{$field} = $source[$field];
            }
        }

        $item->save();

        return $created ? 1 : 0;
    }

    /**
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    private function sourceFromPost(SocialAccount $account, array $post): array
    {
        $postId = data_get($post, 'id');
        $postId = is_string($postId) ? $postId : null;
        $body = data_get($post, 'message') ?? data_get($post, 'caption') ?? data_get($post, 'text') ?? data_get($post, 'name') ?? data_get($post, 'description');
        $permalink = data_get($post, 'permalink_url') ?? data_get($post, 'permalink') ?? data_get($post, 'link');
        $preview = data_get($post, 'full_picture')
            ?? data_get($post, 'thumbnail_url')
            ?? data_get($post, 'media_url')
            ?? data_get($post, 'attachments.data.0.media.image.src')
            ?? data_get($post, 'images.0.source')
            ?? data_get($post, 'picture');
        $mediaType = data_get($post, 'media_type');

        return [
            'source_external_id' => $postId,
            'source_body' => is_string($body) && $body !== '' ? $body : null,
            'source_permalink' => is_string($permalink) && $permalink !== '' ? $permalink : null,
            'source_preview_url' => is_string($preview) && $preview !== '' ? $preview : null,
            'source_media_type' => is_string($mediaType) && $mediaType !== '' ? $mediaType : null,
            'social_post_id' => $postId ? $this->localPostId($account, $postId) : null,
        ];
    }

    private function localPostId(SocialAccount $account, string $externalId): ?int
    {
        $id = SocialPostAccount::query()
            ->where('social_account_id', $account->id)
            ->where('external_id', $externalId)
            ->value('social_post_id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>|null
     */
    private function graphGet(SocialAccount $account, string $path, array $query): ?array
    {
        try {
            $response = Http::timeout((int) config('services.social.timeout', 20))
                ->connectTimeout(3)
                ->acceptJson()
                ->get(
                    $account->platform === SocialPlatform::Threads
                        ? ThreadsGraph::url($path)
                        : FacebookGraph::url($path),
                    $account->platform === SocialPlatform::Threads
                        ? ThreadsGraph::withToken($query, (string) $account->access_token)
                        : FacebookGraph::withToken($query, (string) $account->access_token),
                );
        } catch (ConnectionException|Throwable $exception) {
            $this->lastError = $exception->getMessage();
            Log::warning('Social inbox sync failed.', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            $json = $response->json();
            $message = data_get($json, 'error.message');
            if (is_string($message) && $message !== ''
                && ! str_contains($message, 'does not exist')
                && ! FacebookGraph::isNewPagesExperienceError($json, $message)) {
                $this->lastError = $message;
            }
            Log::warning('Social inbox sync rejected.', [
                'path' => $path,
                'status' => $response->status(),
            ]);

            return null;
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        return $json;
    }

    /**
     * @return array<string, string>
     */
    private function facebookCommentEdges(): array
    {
        $postFields = 'id,message,permalink_url,full_picture,attachments{media{image{src}}},comments.limit(50){id,message,from,created_time}';

        return [
            'published_posts' => $postFields,
            'feed' => $postFields,
            'photos' => 'id,name,link,created_time,images,comments.limit(50){id,message,from,created_time}',
            'videos' => 'id,description,permalink_url,created_time,picture,source,comments.limit(50){id,message,from,created_time}',
        ];
    }
}
