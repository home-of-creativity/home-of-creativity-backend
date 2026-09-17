<?php

namespace App\Console\Commands;

use App\Services\SocialAccountSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncSocialAccountsCommand extends Command
{
    protected $signature = 'social:sync-accounts';

    protected $description = 'Refresh Facebook, Instagram, and Threads page tokens from Graph credentials or a connected Threads OAuth account.';

    public function handle(SocialAccountSync $sync): int
    {
        if (! $sync->configured() && ! $sync->threadsConfigured()) {
            $this->warn('Facebook or Threads credentials are not configured.');

            return self::FAILURE;
        }

        if (! $sync->configured()) {
            $this->warn('Facebook credentials are not configured.');
        }

        $count = $sync->syncFromFacebook();
        Cache::forget('social.landing.instagram.v2.200');
        Cache::forget('social.landing.facebook.v2.200');

        if ($sync->lastError) {
            $this->warn($sync->lastError);
        }

        $this->info("Synced {$count} social account(s). Facebook pages found: {$sync->facebookPagesFound}.");
        if ($sync->threadsError) {
            $this->warn($sync->threadsError);
        }

        return self::SUCCESS;
    }
}
