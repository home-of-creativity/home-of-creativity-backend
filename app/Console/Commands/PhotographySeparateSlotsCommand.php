<?php

namespace App\Console\Commands;

use App\Actions\BookPhotographySlot;
use Illuminate\Console\Command;

class PhotographySeparateSlotsCommand extends Command
{
    protected $signature = 'ops:photography-separate-slots';

    protected $description = 'Keep one confirmed photography shoot in each 5-hour window and move the rest off the calendar.';

    public function handle(BookPhotographySlot $bookPhotographySlot): int
    {
        $released = $bookPhotographySlot->separateSameDayClashes();
        $this->info("Released {$released} overlapping photography booking(s).");

        return self::SUCCESS;
    }
}
