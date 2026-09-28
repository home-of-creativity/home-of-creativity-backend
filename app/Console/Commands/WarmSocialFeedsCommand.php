<?php

namespace App\Console\Commands;

use App\Services\SocialLandingFeed;
use Illuminate\Console\Command;

class WarmSocialFeedsCommand extends Command
{
    protected $signature = 'social:warm-feeds';

    protected $description = 'Refresh the public Facebook and Instagram landing feeds before signed media URLs expire.';

    public function handle(SocialLandingFeed $feed): int
    {
        $feed->warm();
        $this->info('Social landing feeds refreshed.');

        return self::SUCCESS;
    }
}
