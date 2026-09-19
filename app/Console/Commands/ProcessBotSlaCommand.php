<?php

namespace App\Console\Commands;

use App\Actions\ProcessBotSla;
use Illuminate\Console\Command;

class ProcessBotSlaCommand extends Command
{
    protected $signature = 'ops:process-bot-sla {--limit=50}';

    protected $description = 'Send Telegram SLA follow-ups for quotes, receipts, revisions, support, and profiles.';

    public function handle(ProcessBotSla $processBotSla): int
    {
        $counts = $processBotSla->handle((int) $this->option('limit'));
        foreach ($counts as $kind => $sent) {
            $this->line($kind.': '.$sent);
        }

        return self::SUCCESS;
    }
}
