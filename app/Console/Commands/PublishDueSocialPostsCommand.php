<?php

namespace App\Console\Commands;

use App\Services\SocialSchedulePublisher;
use Illuminate\Console\Command;

class PublishDueSocialPostsCommand extends Command
{
    protected $signature = 'social:publish-due';

    protected $description = 'Dispatch publishing jobs for scheduled social posts that are due.';

    public function handle(SocialSchedulePublisher $publisher): int
    {
        $count = $publisher->dispatchDue();
        $this->info("Queued {$count} social post(s).");

        return self::SUCCESS;
    }
}
