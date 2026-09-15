<?php

namespace App\Services;

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Models\SocialAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SocialAccountSync
{
    public ?string $lastError = null;

    public int $facebookPagesFound = 0;

    public function configured(): bool
    {
        return filled(config('services.facebook.access_token'))
            && filled(config('services.facebook.app_id'))
            && filled(config('services.facebook.app_secret'));
    }

    public function syncFromFacebook(?int $connectedBy = null): int
    {
        $this->lastError = null;
        $this->purgeTestAccounts();
        $this->purgePlaceholderAccounts();

        if (! $this->configured()) {
            $this->lastError = 'Facebook credentials are not configured.';

            return 0;
        }

        $pages = $this->fetchPages();
        if ($pages === null) {
            return SocialAccount::query()->where('connection_status', SocialAccountStatus::Connected)->count();
        }

        if ($pages === []) {
            $this->lastError = 'no_pages';
        }

        $keepIds = [];

        foreach ($pages as $page) {
            $keepIds[] = $this->upsertAccount(
                SocialPlatform::Facebook,
                $page['id'],
                $page['name'],
                $page['name'],
                $page['access_token'],
                $connectedBy,
                $page['id'],
            )->id;

            if (! isset($page['instagram'])) {
                continue;
            }

            $instagram = $page['instagram'];
            $keepIds[] = $this->upsertAccount(
                SocialPlatform::Instagram,
                $instagram['id'],
                $instagram['name'] ?: $instagram['username'],
                $instagram['username'],
                $page['access_token'],
                $connectedBy,
                $page['id'],
            )->id;
        }

        $this->markMissingPages($keepIds);

        return count($keepIds);
    }

    public function purgeTestAccounts(): int
    {
        return SocialAccount::query()
            ->where(function ($query): void {
                $query->where('name', 'like', 'E2E%')
                    ->orWhere('handle', 'like', 'e2e%')
                    ->orWhere('page_id', 'like', 'e2e%');
            })
            ->delete();
    }

    public function purgePlaceholderAccounts(): int
    {
        return SocialAccount::query()
            ->get()
            ->filter(fn (SocialAccount $account) => ! ctype_digit((string) $account->page_id))
            ->each(fn (SocialAccount $account) => $account->delete())
            ->count();
    }

    /**
     * @return list<array{id: string, name: string, access_token: string, instagram?: array{id: string, name: string, username: string}}>|null
     */
    private function fetchPages(): ?array
    {
        $pages = [];
        $nextUrl = null;

        do {
            $payload = $nextUrl === null
                ? $this->graphGet('me/accounts', [
                    'fields' => 'id,name,access_token,instagram_business_account{id,username,name}',
                    'limit' => 100,
                ])
                : $this->graphGetUrl($nextUrl);

            if ($payload === null) {
                return $pages === [] ? null : $pages;
            }

            $rows = data_get($payload, 'data');
            if (is_array($rows)) {
                $pages = array_merge($pages, $this->mapPages($rows));
            }

            $next = data_get($payload, 'paging.next');
            $nextUrl = is_string($next) && $next !== '' ? $next : null;
        } while ($nextUrl !== null);

        $this->facebookPagesFound = count($pages);

        return $pages;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{id: string, name: string, access_token: string, instagram?: array{id: string, name: string, username: string}}>
     */
    private function mapPages(array $rows): array
    {
        $pages = [];

        foreach ($rows as $row) {
            $id = data_get($row, 'id');
            $name = data_get($row, 'name');
            $token = data_get($row, 'access_token');
            if (! is_string($id) || $id === '' || ! is_string($name) || $name === '') {
                continue;
            }

            if (! is_string($token) || $token === '') {
                $this->lastError = 'missing_page_token';

                continue;
            }

            $page = [
                'id' => $id,
                'name' => $name,
                'access_token' => $token,
            ];

            $instagramId = data_get($row, 'instagram_business_account.id');
            if (is_string($instagramId) && $instagramId !== '') {
                $page['instagram'] = [
                    'id' => $instagramId,
                    'name' => (string) (data_get($row, 'instagram_business_account.name') ?: $instagramId),
                    'username' => (string) (data_get($row, 'instagram_business_account.username') ?: $instagramId),
                ];
            }

            $pages[] = $page;
        }

        return $pages;
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>|null
     */
    private function graphGet(string $path, array $query): ?array
    {
        $token = trim((string) config('services.facebook.access_token'));

        try {
            $response = Http::timeout((int) config('services.social.timeout', 20))
                ->connectTimeout(3)
                ->acceptJson()
                ->get(FacebookGraph::url($path), FacebookGraph::withToken($query, $token));
        } catch (ConnectionException|Throwable $exception) {
            $this->lastError = $exception->getMessage();
            Log::warning('Facebook account sync failed.', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            $this->lastError = FacebookGraph::errorMessage($response->json(), $response->status());
            Log::warning('Facebook account sync rejected.', [
                'path' => $path,
                'status' => $response->status(),
            ]);

            return null;
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        return $json;
    }

    private function upsertAccount(
        SocialPlatform $platform,
        string $pageId,
        string $name,
        string $handle,
        string $accessToken,
        ?int $connectedBy,
        ?string $facebookPageId = null,
    ): SocialAccount {
        $account = SocialAccount::query()->firstOrNew([
            'platform' => $platform,
            'page_id' => $pageId,
        ]);

        if ($account->exists
            && $account->connection_status === SocialAccountStatus::Disconnected
            && $account->last_error === 'user_disconnected') {
            return $account;
        }

        $isNew = ! $account->exists;
        $account->fill([
            'connection_status' => SocialAccountStatus::Connected,
            'last_error' => null,
            'connected_by' => $connectedBy ?? $account->connected_by,
            'facebook_page_id' => $facebookPageId,
        ]);

        if ($isNew) {
            $account->fill([
                'name' => $name,
                'handle' => $handle,
                'is_active' => true,
            ]);
        }

        $account->access_token = $accessToken;
        $account->save();

        return $account;
    }

    /**
     * @param  list<int>  $keepIds
     */
    private function markMissingPages(array $keepIds): void
    {
        SocialAccount::query()
            ->where('connection_status', SocialAccountStatus::Connected)
            ->when($keepIds !== [], fn ($query) => $query->whereNotIn('id', $keepIds))
            ->update([
                'connection_status' => SocialAccountStatus::Error->value,
                'last_error' => 'page_not_in_token',
            ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function graphGetUrl(string $url): ?array
    {
        try {
            $response = Http::timeout((int) config('services.social.timeout', 20))
                ->connectTimeout(3)
                ->acceptJson()
                ->get($url);
        } catch (ConnectionException|Throwable $exception) {
            $this->lastError = $exception->getMessage();
            Log::warning('Facebook account sync failed.', [
                'url' => $url,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            $this->lastError = FacebookGraph::errorMessage($response->json(), $response->status());
            Log::warning('Facebook account sync rejected.', [
                'url' => $url,
                'status' => $response->status(),
            ]);

            return null;
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        return $json;
    }
}
