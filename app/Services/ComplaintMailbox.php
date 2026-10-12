<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

/**
 * Reads the reports@ inbox over IMAP for client answers to complaint tickets.
 * Only mail whose subject names a ticket is returned; everything else stays unread.
 */
class ComplaintMailbox
{
    public function configured(): bool
    {
        return filled(config('services.complaint_mail.host'))
            && filled(config('services.complaint_mail.username'))
            && filled(config('services.complaint_mail.password'));
    }

    /**
     * Unread mail from the last week whose subject carries a ticket number. `read` marks
     * one message seen once it is filed.
     *
     * @return list<array{from: string, subject: string, body: string, read: callable(): void}>
     */
    public function ticketReplies(): array
    {
        if (! $this->configured()) {
            return [];
        }

        try {
            $client = (new ClientManager)->make([
                'host' => (string) config('services.complaint_mail.host'),
                'port' => (int) config('services.complaint_mail.port', 993),
                'encryption' => (string) config('services.complaint_mail.encryption', 'ssl'),
                'validate_cert' => true,
                'username' => (string) config('services.complaint_mail.username'),
                'password' => (string) config('services.complaint_mail.password'),
                'protocol' => 'imap',
                'timeout' => 20,
            ]);
            $client->connect();
            $folder = $client->getFolderByPath((string) config('services.complaint_mail.folder', 'INBOX'));
            if ($folder === null) {
                return [];
            }
            $messages = $folder->query()
                ->whereUnseen()
                ->whereSince(now()->subDays(7))
                ->leaveUnread()
                ->limit(50)
                ->get();
        } catch (Throwable $exception) {
            Log::warning('Complaint mailbox read failed.', ['error' => $exception->getMessage()]);

            return [];
        }

        $rows = [];
        foreach ($messages as $message) {
            /** @var Message $message */
            $subject = (string) $message->getSubject();
            if (preg_match('/T-\d{5,}/', $subject) !== 1) {
                continue;
            }
            $from = $message->getFrom()->first();
            $body = $message->hasTextBody()
                ? $message->getTextBody()
                : trim(html_entity_decode(strip_tags((string) preg_replace('/<br\s*\/?>|<\/p>|<\/div>/i', "\n", $message->getHTMLBody()))));
            $rows[] = [
                'from' => strtolower(trim((string) ($from->mail ?? ''))),
                'subject' => $subject,
                'body' => $body,
                'read' => function () use ($message): void {
                    try {
                        $message->setFlag('Seen');
                    } catch (Throwable $exception) {
                        Log::warning('Complaint mailbox could not mark a message read.', ['error' => $exception->getMessage()]);
                    }
                },
            ];
        }

        return $rows;
    }
}
