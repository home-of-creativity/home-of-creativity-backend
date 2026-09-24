<?php

namespace App\Services;

use App\Enums\SocialPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Collection;

class SocialPageAccess
{
    /**
     * @return list<array{key: string, name: string, platforms: list<string>}>
     */
    public function catalog(): array
    {
        $accounts = $this->accounts();
        $groups = [];

        foreach ($accounts as $account) {
            $key = $this->keyFor($account, $accounts);
            $groups[$key] ??= [
                'key' => $key,
                'name' => $account->name,
                'platforms' => [],
            ];
            if ($account->platform === SocialPlatform::Facebook) {
                $groups[$key]['name'] = $account->name;
            }
            $groups[$key]['platforms'][] = $account->platform->value;
        }

        return array_values($groups);
    }

    /**
     * @param  list<string>|null  $abilities
     */
    public function allowsAccount(User $user, int $accountId, ?array $abilities = null): bool
    {
        $allowed = $this->allowedAccountIds($user, $abilities);
        if ($allowed === null) {
            return true;
        }

        return in_array($accountId, $allowed, true);
    }

    /**
     * @param  list<int>  $accountIds
     * @param  list<string>|null  $abilities
     */
    public function assertAccounts(User $user, array $accountIds, ?array $abilities = null): void
    {
        foreach ($accountIds as $accountId) {
            abort_unless($this->allowsAccount($user, (int) $accountId, $abilities), 403, 'This page is not assigned to you.');
        }
    }

    /**
     * @param  list<string>|null  $abilities  Null means every granted social ability.
     * @return list<int>|null Null means every page.
     */
    public function allowedAccountIds(User $user, ?array $abilities = null): ?array
    {
        if ($user->seesAllSocialPages()) {
            return null;
        }

        $grants = $user->relationLoaded('pageGrants') ? $user->pageGrants : $user->pageGrants()->get();
        if ($abilities !== null) {
            $grants = $grants->filter(
                fn ($grant): bool => $grant->ability === null || in_array($grant->ability, $abilities, true),
            );
        }

        $keys = $grants->pluck('page_key')->unique()->values()->all();
        if ($keys === []) {
            return [];
        }

        $accounts = $this->accounts();

        return $accounts
            ->filter(fn (SocialAccount $account): bool => in_array($this->keyFor($account, $accounts), $keys, true))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  Collection<int, SocialAccount>  $accounts
     */
    public function keyFor(SocialAccount $account, Collection $accounts): string
    {
        $facebooks = $accounts->filter(
            fn (SocialAccount $row): bool => $row->platform === SocialPlatform::Facebook,
        );

        if ($account->platform === SocialPlatform::Facebook) {
            return $this->facebookKey($account);
        }

        $matched = $this->matchFacebook($account, $facebooks);
        if ($account->platform === SocialPlatform::Threads && $matched === null && $facebooks->count() === 1) {
            $matched = $facebooks->first();
        }

        if ($matched instanceof SocialAccount) {
            return $this->facebookKey($matched);
        }

        return $account->facebook_page_id ?: 'solo-'.$account->id;
    }

    /**
     * @return Collection<int, SocialAccount>
     */
    private function accounts(): Collection
    {
        return SocialAccount::query()
            ->get(['id', 'platform', 'name', 'handle', 'page_id', 'facebook_page_id']);
    }

    private function facebookKey(SocialAccount $account): string
    {
        return $account->facebook_page_id ?: ($account->page_id ?: 'fb-'.$account->id);
    }

    /**
     * @param  Collection<int, SocialAccount>  $facebooks
     */
    private function matchFacebook(SocialAccount $account, Collection $facebooks): ?SocialAccount
    {
        $byPageId = $facebooks->first(function (SocialAccount $facebook) use ($account): bool {
            return filled($account->facebook_page_id)
                && ($facebook->facebook_page_id === $account->facebook_page_id || $facebook->page_id === $account->facebook_page_id);
        });
        if ($byPageId instanceof SocialAccount) {
            return $byPageId;
        }

        if (in_array($account->platform, [SocialPlatform::Instagram, SocialPlatform::Threads], true)) {
            $byName = $facebooks->first(fn (SocialAccount $facebook): bool => $facebook->name === $account->name);
            if ($byName instanceof SocialAccount) {
                return $byName;
            }
        }

        return null;
    }
}
