<?php

namespace App\Console\Commands;

use App\Actions\BookPhotographySlot;
use Illuminate\Console\Command;

class PhotographyDayBeforeCommand extends Command
{
    protected $signature = 'ops:photography-day-before';

    protected $description = 'Remind the client and the photographer the day before an agreed shoot, on its current start.';

    public function handle(BookPhotographySlot $bookPhotographySlot): int
    {
        $sent = $bookPhotographySlot->sendDayBeforeReminders();
        $this->info("Sent {$sent} photography reminder(s).");

        return self::SUCCESS;
    }
}
