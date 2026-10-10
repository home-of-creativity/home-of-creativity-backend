<?php

namespace App\Console\Commands;

use App\Actions\BookPhotographySlot;
use Illuminate\Console\Command;

class PhotographyExpireCommand extends Command
{
    protected $signature = 'ops:photography-expire';

    protected $description = 'Close photography requests and offers unanswered for 48 hours, and return unanswered moves to the agreed time.';

    public function handle(BookPhotographySlot $bookPhotographySlot): int
    {
        $closed = $bookPhotographySlot->expireStale();
        $nudged = $bookPhotographySlot->remindUnmarked();
        $this->info("Closed {$closed} photography booking(s). Nudged {$nudged} finished shoot(s).");

        return self::SUCCESS;
    }
}
