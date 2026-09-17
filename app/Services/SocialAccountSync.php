<?php

namespace App\Services;

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Models\SocialAccount;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SocialAccountSync
{
    public ?string $lastError = null;

    public ?string $threadsError = null;

    public int $facebookPagesFound = 0;

    public function configured(): bool
    {
        return filled(config('services.facebook.access_token'))
            && filled(config('services.facebook.app_id'))
            && filled(config('services.facebook.app_secret'));
    }

    public function threadsConfigured(): bool
    {
        return ThreadsGraph::configured();
    }

    public function syncFromFacebook(?int $connectedBy = null): int
    {
        $this->lastError = null;
        $this->purgeTestAccounts();
        $this->purgePlaceholderAccounts();

        if (! $this->configured()) {
            $this->lastError = 'Facebook credentials are not configured.';

            return $this->syncStandaloneThreads($connectedBy);
        }

        $pages = $this->fetchPages();
        if ($pages === null) {
            return SocialAccount::query()->where('connection_status', SocialAccountStatus::Connected)->count();
        }

        if ($pages === []) {
            $this->lastError = 'no_pages';
        }

        $keepIds = [];
        $attachedThreadsIds = [];
        $instagramPages = [];

        foreach ($pages as $page) {
            $this->rememberSyncedAccount($keepIds, $this->upsertAccount(
                SocialPlatform::Facebook,
                $page['id'],
                $page['name'],
                $page['name'],
                $page['access_token'],
                $connectedBy,
                $page['id'],
            ));

            if (! isset($page['instagram'])) {
                continue;
            }

            $instagram = $page['instagram'];
            $instagramPages[] = $page;
            $this->rememberSyncedAccount($keepIds, $this->upsertAccount(
                SocialPlatform::Instagram,
                $instagram['id'],
                $instagram['name'] ?: $instagram['username'],
                $instagram['username'],
                $page['access_token'],
                $connectedBy,
                $page['id'],
            ));
        }

        $threadsProfiles = $this->fetchThreadsProfiles();
        $threadsProfile = $threadsProfiles[0] ?? null;

        foreach ($instagramPages as $page) {
            $threads = $this->resolveThreadsForPage($page, $threadsProfile, $instagramPages);
            if ($threads === null) {
                continue;
            }

            $this->rememberSyncedAccount($keepIds, $this->upsertAccount(
                SocialPlatform::Threads,
                $threads['id'],
                $threads['name'],
                $threads['username'],
                $threads['token'],
                $connectedBy,
                $page['id'],
                $threads['token'] === null || $threads['token'] === '' ? 'threads_token_missing' : null,
            ));
            $attachedThreadsIds[] = $threads['id'];
        }

        foreach ($threadsProfiles as $profile) {
            if (in_array($profile['id'], $attachedThreadsIds, true)) {
                continue;
            }

            $this->rememberSyncedAccount($keepIds, $this->upsertAccount(
                SocialPlatform::Threads,
                $profile['id'],
                $profile['name'] ?: $profile['username'],
                $profile['username'],
                $profile['token'],
                $connectedBy,
                $this->matchingFacebookPageId($profile['username']),
            ));
        }

        $this->rememberExistingThreadsAccounts($keepIds);
        $this->markMissingPages($keepIds);

        return count($keepIds);
    }

    public function connectThreadsToken(string $accessToken, ?int $connectedBy = null, ?DateTimeInterface $expiresAt = null): ?SocialAccount
    {
        $this->threadsError = null;
        $profile = $this->fetchThreadsProfileWithToken($accessToken);
        if ($profile === null) {
            return null;
        }

        $account = $this->upsertAccount(
            SocialPlatform::Threads,
            $profile['id'],
            $profile['name'] ?: $profile['username'],
            $profile['username'],
            $profile['token'],
            $connectedBy,
            $this->matchingFacebookPageId($profile['username']),
            null,
            $expiresAt,
            true,
        );

        return $this->isManuallyDisconnected($account) ? null : $account;
    }

    private function syncStandaloneThreads(?int $connectedBy): int
    {
        $count = 0;

        foreach ($this->fetchThreadsProfiles() as $profile) {
            $account = $this->upsertAccount(
                SocialPlatform::Threads,
                $profile['id'],
                $profile['name'] ?: $profile['username'],
                $profile['username'],
                $profile['token'],
                $connectedBy,
                $this->matchingFacebookPageId($profile['username']),
            );

            if (! $this->isManuallyDisconnected($account)) {
                $count++;
            }
        }

        return $count;
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

    /**
     * @return list<array{id: string, username: string, name: string, token: string}>
     */
    private function fetchThreadsProfiles(): array
    {
        $profiles = [];
        $lastError = null;

        foreach ($this->threadsAccessTokens() as $token) {
            $this->threadsError = null;
            $profile = $this->fetchThreadsProfileWithToken($token);
            if ($profile === null) {
                $lastError = $this->threadsError;

                continue;
            }

            $profiles[$profile['id']] = $profile;
        }

        $this->threadsError = $profiles === [] ? $lastError : null;

        return array_values($profiles);
    }

    /**
     * @return array{id: string, username: string, name: string, token: string}|null
     */
    private function fetchThreadsProfileWithToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $payload = $this->threadsGet('me', [
            'fields' => 'id,username,name',
        ], $token);

        $id = data_get($payload, 'id');
        $username = data_get($payload, 'username');
        if (! is_string($id) || $id === '' || ! is_string($username) || $username === '') {
            if ($this->threadsError === null) {
                $this->threadsError = 'threads_profile_missing';
            }

            return null;
        }

        $name = data_get($payload, 'name');

        return [
            'id' => $id,
            'username' => $username,
            'name' => is_string($name) && $name !== '' ? $name : $username,
            'token' => $token,
        ];
    }

    /**
     * @return list<string>
     */
    private function threadsAccessTokens(): array
    {
        $tokens = [];
        $envToken = trim((string) config('services.threads.access_token'));
        if ($envToken !== '') {
            $tokens[] = $envToken;
        }

        foreach ($this->storedThreadsAccounts() as $account) {
            $token = trim((string) $account->access_token);
            if ($token !== '' && ! in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * @return list<SocialAccount>
     */
    private function storedThreadsAccounts(): array
    {
        return SocialAccount::query()
            ->where('platform', SocialPlatform::Threads)
            ->where('connection_status', SocialAccountStatus::Connected)
            ->get()
            ->all();
    }

    private function matchingFacebookPageId(string $username): ?string
    {
        $instagram = SocialAccount::query()
            ->where('platform', SocialPlatform::Instagram)
            ->where('handle', $username)
            ->first();

        return filled($instagram?->facebook_page_id) ? (string) $instagram->facebook_page_id : null;
    }

    /**
     * @param  list<int>  $keepIds
     */
    private function rememberExistingThreadsAccounts(array &$keepIds): void
    {
        foreach ($this->storedThreadsAccounts() as $account) {
            $this->rememberSyncedAccount($keepIds, $account);
        }
    }

    /**
     * @param  array{id: string, name: string, access_token: string, instagram?: array{id: string, name: string, username: string}}  $page
     * @param  array{id: string, username: string, name: string, token: string}|null  $threadsProfile
     * @param  list<array{id: string, name: string, access_token: string, instagram?: array{id: string, name: string, username: string}}>  $instagramPages
     * @return array{id: string, username: string, name: string, token: ?string}|null
     */
    private function resolveThreadsForPage(array $page, ?array $threadsProfile, array $instagramPages): ?array
    {
        $instagram = $page['instagram'] ?? null;
        if (! is_array($instagram)) {
            return null;
        }

        $connected = $this->fetchConnectedThreadsUser((string) $instagram['id']);
        $instagramUsername = (string) ($instagram['username'] ?? '');

        if ($threadsProfile !== null) {
            $sameId = $connected !== null && $connected['id'] === $threadsProfile['id'];
            $sameHandle = $instagramUsername !== ''
                && strcasecmp($threadsProfile['username'], $instagramUsername) === 0;
            $onlyInstagram = count($instagramPages) === 1 && $connected === null;

            if (! $sameId && ! $sameHandle && ! $onlyInstagram) {
                return $connected;
            }

            return [
                'id' => $threadsProfile['id'],
                'username' => $threadsProfile['username'],
                'name' => $threadsProfile['name'] ?: (string) ($instagram['name'] ?? $threadsProfile['username']),
                'token' => $threadsProfile['token'],
            ];
        }

        return $connected;
    }

    /**
     * @return array{id: string, username: string, name: string, token: ?string}|null
     */
    private function fetchConnectedThreadsUser(string $igUserId): ?array
    {
        foreach (['connected_threads_user', 'instagram_backed_threads_user'] as $edge) {
            $payload = $this->graphGetOptional($igUserId.'/'.$edge, [
                'fields' => 'threads_user_id,username,name',
            ]);
            $row = $this->firstThreadsUserRow($payload);
            if ($row === null) {
                continue;
            }

            return $row;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array{id: string, username: string, name: string, token: ?string}|null
     */
    private function firstThreadsUserRow(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $row = data_get($payload, 'data.0');
        if (! is_array($row)) {
            $row = $payload;
        }

        $id = data_get($row, 'threads_user_id');
        if (! is_string($id) || $id === '') {
            return null;
        }

        $username = data_get($row, 'username');
        $name = data_get($row, 'name');

        return [
            'id' => $id,
            'username' => is_string($username) && $username !== '' ? $username : $id,
            'name' => is_string($name) && $name !== '' ? $name : (is_string($username) && $username !== '' ? $username : $id),
            'token' => null,
        ];
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>|null
     */
    private function graphGetOptional(string $path, array $query): ?array
    {
        $previous = $this->lastError;
        $payload = $this->graphGet($path, $query);
        $this->lastError = $previous;

        return $payload;
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>|null
     */
    private function threadsGet(string $path, array $query, string $token): ?array
    {
        try {
            $response = Http::timeout((int) config('services.social.timeout', 20))
                ->connectTimeout(3)
                ->acceptJson()
                ->get(ThreadsGraph::url($path), ThreadsGraph::withToken($query, $token));
        } catch (ConnectionException|Throwable $exception) {
            $this->threadsError = $exception->getMessage();
            Log::warning('Threads account sync failed.', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            $this->threadsError = ThreadsGraph::errorMessage($response->json(), $response->status());
            Log::warning('Threads account sync rejected.', [
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
        ?string $accessToken,
        ?int $connectedBy,
        ?string $facebookPageId = null,
        ?string $missingTokenError = null,
        ?DateTimeInterface $expiresAt = null,
        bool $forceReconnect = false,
    ): SocialAccount {
        $account = SocialAccount::query()->firstOrNew([
            'platform' => $platform,
            'page_id' => $pageId,
        ]);

        if (! $forceReconnect && $this->isManuallyDisconnected($account)) {
            return $account;
        }

        $isNew = ! $account->exists;
        $hasToken = is_string($accessToken) && $accessToken !== '';
        $keepExistingToken = ! $hasToken && $account->hasToken();
        $connected = $hasToken || $keepExistingToken;

        $account->fill([
            'connection_status' => $connected
                ? SocialAccountStatus::Connected
                : SocialAccountStatus::Error,
            'last_error' => $connected ? null : ($missingTokenError ?: 'missing_token'),
            'connected_by' => $connectedBy ?? $account->connected_by,
        ]);

        if (is_string($facebookPageId) && $facebookPageId !== '') {
            $account->facebook_page_id = $facebookPageId;
        }

        if ($isNew) {
            $account->fill([
                'name' => $name,
                'handle' => $handle,
                'is_active' => true,
            ]);
        }

        if ($forceReconnect) {
            $account->is_active = true;
        }

        if ($hasToken) {
            $account->access_token = $accessToken;
        }
        if ($expiresAt !== null) {
            $account->token_expires_at = $expiresAt;
        }
        $account->save();

        return $account;
    }

    /**
     * @param  list<int>  $keepIds
     */
    private function rememberSyncedAccount(array &$keepIds, SocialAccount $account): void
    {
        if ($this->isManuallyDisconnected($account)) {
            return;
        }

        if (! in_array($account->id, $keepIds, true)) {
            $keepIds[] = $account->id;
        }
    }

    private function isManuallyDisconnected(SocialAccount $account): bool
    {
        return $account->exists
            && $account->connection_status === SocialAccountStatus::Disconnected
            && $account->last_error === 'user_disconnected';
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
