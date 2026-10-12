<?php

namespace App\Actions;

use App\Enums\EmployeeProfession;
use App\Models\Client;
use App\Models\Complaint;
use App\Models\ComplaintMessage;
use App\Models\User;
use App\Services\TelegramNotifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A complaint becomes a ticket when the team answers it. Replies go back on the chat the
 * complaint came from (WhatsApp or Telegram) or by email. A bot reply carries a button;
 * the client's next message after it lands on the same ticket.
 */
class ComplaintTickets
{
    public const REPLY_CALLBACK = 'tkt:';

    public function __construct(
        private TelegramNotifier $telegram,
        private NotifyEmployees $notifyEmployees,
    ) {}

    public function open(Complaint $complaint): Complaint
    {
        if (filled($complaint->ticket_number)) {
            if ($complaint->status === Complaint::OPEN) {
                $complaint->forceFill(['status' => Complaint::IN_PROGRESS])->save();
            }

            return $complaint;
        }

        $complaint->forceFill([
            'ticket_number' => 'T-'.str_pad((string) $complaint->id, 5, '0', STR_PAD_LEFT),
            'ticket_opened_at' => now(),
            'status' => Complaint::IN_PROGRESS,
            'reviewed_at' => null,
        ])->save();

        return $complaint;
    }

    /**
     * @param  'bot'|'email'|'note'  $channel
     */
    public function reply(Complaint $complaint, User $user, string $body, string $channel, bool $resolve): ComplaintMessage
    {
        $complaint->loadMissing('client');
        $this->open($complaint);
        $body = trim($body);

        $delivered = false;
        $recorded = $channel;
        if ($channel === 'bot') {
            $chatId = $this->chatId($complaint);
            if ($chatId === null) {
                throw ValidationException::withMessages(['channel' => 'العميل لا يصله البوت الآن. أرسل الرد بالإيميل.']);
            }
            $delivered = $this->sendBot($chatId, $complaint, $this->text($complaint, $body));
            $recorded = Client::isWhatsAppKey($chatId) ? 'whatsapp' : 'telegram';
        } elseif ($channel === 'email') {
            $email = $this->email($complaint);
            if ($email === null) {
                throw ValidationException::withMessages(['channel' => 'لا يوجد إيميل لهذا العميل.']);
            }
            $delivered = $this->sendEmail($email, $complaint, $body);
        }

        $message = $complaint->messages()->create([
            'author' => ComplaintMessage::STAFF,
            'user_id' => $user->id,
            'channel' => $recorded,
            'body' => $body,
            'delivered' => $channel === 'note' ? true : $delivered,
        ]);

        if ($resolve) {
            $complaint->forceFill(['status' => Complaint::RESOLVED, 'reviewed_at' => now()])->save();
        }

        return $message;
    }

    public function setStatus(Complaint $complaint, string $status): Complaint
    {
        if ($status === Complaint::IN_PROGRESS) {
            return $this->open($complaint);
        }
        $complaint->forceFill([
            'status' => $status,
            'reviewed_at' => $status === Complaint::RESOLVED ? now() : null,
        ])->save();

        return $complaint;
    }

    /** The client pressed «reply» under a ticket message: the next text goes to that ticket. */
    public function awaitReply(Client $client, string $chatId, string $key, string $data, string $lang): bool
    {
        $id = (int) substr($data, strlen(self::REPLY_CALLBACK));
        $complaint = Complaint::query()->whereKey($id)->where('client_id', $client->id)->first();
        if (! $complaint instanceof Complaint || blank($complaint->ticket_number)) {
            $this->say($chatId, $lang, 'ما لقينا هالتذكرة.', 'That ticket was not found.');

            return true;
        }
        Cache::put($this->awaitKey($key), $complaint->id, now()->addHours(24));
        $this->say(
            $chatId,
            $lang,
            "اكتب ردك على التذكرة {$complaint->ticket_number}. ابعت «إلغاء» إذا ما بدك ترد.",
            "Write your reply to ticket {$complaint->ticket_number}. Send «cancel» to skip.",
        );

        return true;
    }

    public function awaiting(string $key): bool
    {
        return Cache::has($this->awaitKey($key));
    }

    public function forget(string $key): void
    {
        Cache::forget($this->awaitKey($key));
    }

    /** Files the client's text on the ticket they chose to answer. */
    public function clientReply(Client $client, string $chatId, string $key, string $text, string $lang): bool
    {
        $id = Cache::pull($this->awaitKey($key));
        $complaint = Complaint::query()->whereKey((int) $id)->where('client_id', $client->id)->first();
        if (! $complaint instanceof Complaint) {
            return false;
        }

        $complaint->messages()->create([
            'author' => ComplaintMessage::CLIENT,
            'channel' => Client::isWhatsAppKey($chatId) ? 'whatsapp' : 'telegram',
            'body' => mb_substr(trim($text), 0, 4000),
            'delivered' => true,
        ]);
        $complaint->forceFill(['status' => Complaint::IN_PROGRESS, 'reviewed_at' => null, 'chat_id' => $chatId])->save();

        $who = filled($client->company_name) ? (string) $client->company_name : (string) ($client->name ?: $client->phone);
        $this->notifyEmployees->handlePlain(
            EmployeeProfession::Sales,
            "ردّ {$who} على التذكرة {$complaint->ticket_number}: ".mb_substr(trim($text), 0, 500),
        );
        $this->say($chatId, $lang, "وصل ردك على التذكرة {$complaint->ticket_number}.", "Your reply reached ticket {$complaint->ticket_number}.");

        return true;
    }

    /**
     * Where a reply can go for this complaint.
     *
     * @return array{bot: string|null, email: string|null}
     */
    public function channels(Complaint $complaint): array
    {
        $chatId = $this->chatId($complaint);

        return [
            'bot' => $chatId === null ? null : (Client::isWhatsAppKey($chatId) ? 'whatsapp' : 'telegram'),
            'email' => $this->email($complaint),
        ];
    }

    private function chatId(Complaint $complaint): ?string
    {
        $client = $complaint->client;
        $candidates = [
            $complaint->chat_id,
            $client === null ? null : Cache::get('hoc:client-reply:'.$client->id),
            $client?->telegram_user_id,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && $this->telegram->canReachClient($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function email(Complaint $complaint): ?string
    {
        $email = trim((string) $complaint->client?->email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function text(Complaint $complaint, string $body): string
    {
        return "تذكرة {$complaint->ticket_number} · {$complaint->title}\n\n{$body}";
    }

    private function sendBot(string $chatId, Complaint $complaint, string $text): bool
    {
        try {
            $this->telegram->sendInlineActions($chatId, $text, [
                ['text' => '↩️ رد على التذكرة', 'callback_data' => self::REPLY_CALLBACK.$complaint->id],
            ]);

            return true;
        } catch (Throwable $exception) {
            Log::warning('Complaint reply on the bot failed.', ['complaint' => $complaint->id, 'error' => $exception->getMessage()]);

            return false;
        }
    }

    private function sendEmail(string $email, Complaint $complaint, string $body): bool
    {
        try {
            $from = (string) config('mail.mailers.reports.username');
            $mailer = filled(config('mail.mailers.reports.password')) ? 'reports' : (string) config('mail.default');
            $text = $this->text($complaint, $body)."\n\nللرد، رد على هذا الإيميل أو اضغط «رد على التذكرة» في البوت.";
            Mail::mailer($mailer)->raw($text, function ($message) use ($email, $from, $complaint): void {
                $message->to($email)->subject("تذكرة {$complaint->ticket_number} · Home of Creativity");
                if ($from !== '') {
                    $message->from($from, 'Home of Creativity');
                    $message->replyTo($from, 'Home of Creativity');
                }
            });

            return true;
        } catch (Throwable $exception) {
            Log::warning('Complaint reply by email failed.', ['complaint' => $complaint->id, 'error' => $exception->getMessage()]);

            return false;
        }
    }

    private function say(string $chatId, string $lang, string $arabic, string $english): void
    {
        try {
            $this->telegram->send($chatId, $lang === 'en' ? $english : $arabic);
        } catch (Throwable $exception) {
            Log::warning('Ticket bot message failed.', ['error' => $exception->getMessage()]);
        }
    }

    private function awaitKey(string $key): string
    {
        return 'hoc:ticket-reply:'.$key;
    }
}
