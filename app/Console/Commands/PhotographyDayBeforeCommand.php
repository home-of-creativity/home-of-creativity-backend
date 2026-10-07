<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PhotographyDayBeforeCommand extends Command
{
    protected $signature = 'ops:photography-day-before';

    protected $description = 'Photography day-before client reminders are off. The department stays a ClickUp task.';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
