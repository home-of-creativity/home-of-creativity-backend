<?php

namespace App\Console\Commands;

use App\Actions\RegisterDriveWatch;
use Illuminate\Console\Command;

class RenewDriveWatchCommand extends Command
{
    protected $signature = 'ops:renew-drive-watch';

    protected $description = 'Keep the Google Drive push channel that notifies Laravel when a file is uploaded.';

    public function handle(RegisterDriveWatch $registerDriveWatch): int
    {
        if ($registerDriveWatch->handle()) {
            $this->info('Drive watch is registered.');

            return self::SUCCESS;
        }

        $this->warn('Drive watch was not registered.');

        return self::SUCCESS;
    }
}
