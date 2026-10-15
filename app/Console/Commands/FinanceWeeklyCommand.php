<?php

namespace App\Console\Commands;

use App\Actions\WeeklyFinanceSummary;
use Illuminate\Console\Command;

class FinanceWeeklyCommand extends Command
{
    protected $signature = 'ops:finance-weekly';

    protected $description = 'Send management the finance summary of the week that just ended.';

    public function handle(WeeklyFinanceSummary $summary): int
    {
        $text = $summary->send();
        $this->info($text === null ? 'This week was already sent.' : 'Weekly finance summary sent.');

        return self::SUCCESS;
    }
}
