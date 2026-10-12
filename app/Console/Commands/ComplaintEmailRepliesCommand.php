<?php

namespace App\Console\Commands;

use App\Actions\ComplaintTickets;
use App\Services\ComplaintMailbox;
use Illuminate\Console\Command;

class ComplaintEmailRepliesCommand extends Command
{
    protected $signature = 'ops:complaint-email-replies';

    protected $description = 'File client email answers to complaint tickets from the reports@ inbox.';

    public function handle(ComplaintMailbox $mailbox, ComplaintTickets $tickets): int
    {
        $filed = 0;
        foreach ($mailbox->ticketReplies() as $mail) {
            if ($tickets->emailReply($mail['from'], $mail['subject'], $mail['body'])) {
                ($mail['read'])();
                $filed++;
            }
        }
        $this->info("Filed {$filed} email repl(ies) on tickets.");

        return self::SUCCESS;
    }
}
