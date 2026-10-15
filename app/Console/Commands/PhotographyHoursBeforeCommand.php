<?php

namespace App\Console\Commands;

use App\Actions\BookPhotographySlot;
use Illuminate\Console\Command;

class PhotographyHoursBeforeCommand extends Command
{
    protected $signature = 'ops:photography-hours-before';

    protected $description = 'Remind the client and the photographer two hours before an agreed shoot.';

    public function handle(BookPhotographySlot $bookPhotographySlot): int
    {
        $sent = $bookPhotographySlot->sendHoursBeforeReminders();
        $this->info("Sent {$sent} photography reminder(s).");

        return self::SUCCESS;
    }
}
