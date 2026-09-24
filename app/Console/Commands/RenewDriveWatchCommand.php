<?php

namespace App\Console\Commands;

use App\Actions\RegisterDriveWatch;
use App\Services\DevAlert;
use Illuminate\Console\Command;

class RenewDriveWatchCommand extends Command
{
    protected $signature = 'ops:renew-drive-watch';

    protected $description = 'Keep the Google Drive push channel that notifies Laravel when a file is uploaded.';

    public function handle(RegisterDriveWatch $registerDriveWatch, DevAlert $alert): int
    {
        if ($registerDriveWatch->handle()) {
            $alert->recover('drive-watch', 'Drive watch renewed');
            $this->info('Drive watch is registered.');

            return self::SUCCESS;
        }

        $alert->once('drive-watch', 'Drive watch was not registered. Folder uploads wait for the minute poll.', 360);
        $this->warn('Drive watch was not registered.');

        return self::SUCCESS;
    }
}
