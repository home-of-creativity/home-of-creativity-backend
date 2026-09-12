<?php

namespace App\Console\Commands;

use App\Services\SocialInboxSync;
use Illuminate\Console\Command;

class SyncSocialInboxCommand extends Command
{
    protected $signature = 'social:sync-inbox';

    protected $description = 'Pull comments and messages from connected social accounts.';

    public function handle(SocialInboxSync $sync): int
    {
        $imported = $sync->syncAll();
        $this->info("Imported {$imported} inbox item(s).");

        return self::SUCCESS;
    }
}
