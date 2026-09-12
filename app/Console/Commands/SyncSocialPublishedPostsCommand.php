<?php

namespace App\Console\Commands;

use App\Services\SocialPublishedPostSync;
use Illuminate\Console\Command;

class SyncSocialPublishedPostsCommand extends Command
{
    protected $signature = 'social:sync-posts';

    protected $description = 'Remove local social posts that were deleted on Facebook or Instagram.';

    public function handle(SocialPublishedPostSync $sync): int
    {
        $deleted = $sync->prune();
        $this->info("Removed {$deleted} social post(s) deleted remotely.");

        return self::SUCCESS;
    }
}
