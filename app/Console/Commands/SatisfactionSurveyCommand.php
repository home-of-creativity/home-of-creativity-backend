<?php

namespace App\Console\Commands;

use App\Actions\SatisfactionSurvey;
use Illuminate\Console\Command;

class SatisfactionSurveyCommand extends Command
{
    protected $signature = 'ops:satisfaction-survey';

    protected $description = 'Ask WhatsApp clients to rate a project a few hours after it is completed.';

    public function handle(SatisfactionSurvey $survey): int
    {
        $sent = $survey->sendDue();
        $this->info("Asked {$sent} client(s).");

        return self::SUCCESS;
    }
}
