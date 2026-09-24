<?php

namespace App\Console\Commands;

use App\Services\DevAlert;
use App\Services\DevDigest;
use Illuminate\Console\Command;

class DevDigestCommand extends Command
{
    protected $signature = 'ops:dev-digest';

    protected $description = 'Send one morning developer summary.';

    public function handle(DevAlert $alert, DevDigest $digest): int
    {
        $alert->send($digest->text());
        $this->info('Developer digest sent');

        return self::SUCCESS;
    }
}
