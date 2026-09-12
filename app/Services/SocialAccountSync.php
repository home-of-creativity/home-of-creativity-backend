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

    public function configured(): bool
    {
        return filled(config('services.facebook.access_token'))
            && filled(config('services.facebook.app_id'))
            && filled(config('services.facebook.app_secret'));
    }

    public function syncFromFacebook(?int $connectedBy = null): int
    {
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
            )->id;
        }

        if ($keepIds === []) {
            SocialAccount::query()->delete();
        } else {
            SocialAccount::query()->whereNotIn('id', $keepIds)->delete();
        }

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

    private function graphErrorMessage(mixed $json, int $status): string
    {
        $message = data_get($json, 'error.message');

        return is_string($message) && $message !== ''
            ? $message
            : 'Facebook Graph HTTP '.$status;
    }

    /**
     * @return list<array{id: string, name: string, access_token: string, instagram?: array{id: string, name: string, username: string}}>|null
     */
    private function fetchPages(): ?array
    {
        $accounts = $this->graphGet('me/accounts', [
            'fields' => 'id,name,access_token,instagram_business_account{id,username,name}',
        ]);

        if ($accounts === null) {
            return null;
        }

        $rows = data_get($accounts, 'data');

        return is_array($rows) ? $this->mapPages($rows) : [];
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
            $token = data_get($row, 'access_token') ?: config('services.facebook.access_token');
            if (! is_string($id) || $id === '' || ! is_string($name) || $name === '' || ! is_string($token) || $token === '') {
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
        $secret = trim((string) config('services.facebook.app_secret'));

        try {
            $response = Http::timeout((int) config('services.social.timeout', 20))
                ->connectTimeout(3)
                ->acceptJson()
                ->get($this->graphUrl($path), [
                    ...$query,
                    'access_token' => $token,
                    'appsecret_proof' => hash_hmac('sha256', $token, $secret),
                ]);
        } catch (ConnectionException|Throwable $exception) {
            $this->lastError = $exception->getMessage();
            Log::warning('Facebook account sync failed.', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            $this->lastError = $this->graphErrorMessage($response->json(), $response->status());
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
    ): SocialAccount {
        $account = SocialAccount::query()->firstOrNew([
            'platform' => $platform,
            'page_id' => $pageId,
        ]);

        $account->fill([
            'name' => $name,
            'handle' => $handle,
            'is_active' => true,
            'connection_status' => SocialAccountStatus::Connected,
            'last_error' => null,
            'connected_by' => $connectedBy ?? $account->connected_by,
        ]);
        $account->access_token = $accessToken;
        $account->save();

        return $account;
    }

    private function graphUrl(string $path): string
    {
        $base = rtrim((string) config('services.social.graph_base', 'https://graph.facebook.com/v21.0'), '/');

        return $base.'/'.ltrim($path, '/');
    }
}
