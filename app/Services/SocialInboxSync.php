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

        if (! in_array($account->platform, [SocialPlatform::Facebook, SocialPlatform::Instagram], true)) {
            return 0;
        }

        $imported = 0;
        $imported += $this->importCommentsFromPath($account, $account->page_id.'/published_posts');
        $imported += $this->importCommentsFromPath($account, $account->page_id.'/feed');
        $imported += $this->importCommentsFromPublishedTargets($account);
        $imported += $this->importMessages($account);
        $this->pruneDeletedComments($account);

        return $imported;
    }

    private function importCommentsFromPath(SocialAccount $account, string $path): int
    {
        $payload = $this->graphGet($account, $path, [
            'fields' => 'id,comments.limit(50){id,message,from,created_time}',
            'limit' => '25',
        ]);

        if ($payload === null) {
            return 0;
        }

        $imported = 0;
        foreach (data_get($payload, 'data', []) as $post) {
            $postId = data_get($post, 'id');
            if (! is_string($postId) || $postId === '') {
                continue;
            }

            $imported += $this->syncCommentsForPost($account, $postId, data_get($post, 'comments.data', []));
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
                $payload = $this->graphGet($account, (string) $target->external_id.'/comments', [
                    'fields' => 'id,message,from,created_time',
                    'limit' => '50',
                ]);

                if ($payload === null) {
                    return;
                }

                $imported += $this->syncCommentsForPost($account, (string) $target->external_id, data_get($payload, 'data', []));
            });

        return $imported;
    }

    private function importMessages(SocialAccount $account): int
    {
        $payload = $this->graphGet($account, $account->page_id.'/conversations', [
            'fields' => 'id,updated_time,messages.limit(20){id,message,from,created_time}',
            'limit' => '25',
        ]);

        if ($payload === null) {
            return 0;
        }

        $imported = 0;
        foreach (data_get($payload, 'data', []) as $conversation) {
            foreach (data_get($conversation, 'messages.data', []) as $message) {
                $fromId = (string) data_get($message, 'from.id');
                if ($fromId !== '' && $fromId === (string) $account->page_id) {
                    continue;
                }

                $imported += $this->storeItem(
                    $account,
                    SocialInboxKind::Message,
                    data_get($message, 'id'),
                    data_get($message, 'message'),
                    data_get($message, 'from.name'),
                    data_get($message, 'from.id'),
                    data_get($message, 'created_time'),
                );
            }
        }

        return $imported;
    }

    private function syncCommentsForPost(SocialAccount $account, string $postId, mixed $comments): int
    {
        $rows = is_array($comments) ? $comments : [];
        $imported = $this->storeComments($account, $rows, $postId);
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
     */
    private function storeComments(SocialAccount $account, array $comments, string $sourceId): int
    {
        $imported = 0;
        foreach ($comments as $comment) {
            $imported += $this->storeItem(
                $account,
                SocialInboxKind::Comment,
                data_get($comment, 'id'),
                data_get($comment, 'message'),
                data_get($comment, 'from.name'),
                data_get($comment, 'from.id'),
                data_get($comment, 'created_time'),
                $sourceId,
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

    private function storeItem(
        SocialAccount $account,
        SocialInboxKind $kind,
        mixed $externalId,
        mixed $body,
        mixed $authorName,
        mixed $authorHandle,
        mixed $occurredAt,
        ?string $sourceExternalId = null,
    ): int {
        if (! is_string($externalId) || $externalId === '') {
            return 0;
        }

        $item = SocialInboxItem::query()->firstOrCreate(
            [
                'social_account_id' => $account->id,
                'external_id' => $externalId,
            ],
            [
                'kind' => $kind,
                'source_external_id' => $sourceExternalId,
                'author_name' => is_string($authorName) && $authorName !== '' ? $authorName : 'Unknown',
                'author_handle' => is_string($authorHandle) ? $authorHandle : null,
                'body' => is_string($body) && $body !== '' ? $body : '—',
                'occurred_at' => is_string($occurredAt) ? $occurredAt : now(),
            ]
        );

        return $item->wasRecentlyCreated ? 1 : 0;
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
                ->get($this->graphUrl($path), [
                    ...$query,
                    'access_token' => $account->access_token,
                ]);
        } catch (ConnectionException|Throwable $exception) {
            $this->lastError = $exception->getMessage();
            Log::warning('Social inbox sync failed.', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            $message = data_get($response->json(), 'error.message');
            if (is_string($message) && $message !== '' && ! str_contains($message, 'does not exist')) {
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

    private function graphUrl(string $path): string
    {
        $base = rtrim((string) config('services.social.graph_base', 'https://graph.facebook.com/v21.0'), '/');

        return $base.'/'.ltrim($path, '/');
    }
}
