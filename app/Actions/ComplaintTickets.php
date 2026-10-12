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
 * complaint came from (WhatsApp or Telegram) or by email.
 *
 * After a bot reply the chat watches the ticket for 24 hours: what the client writes next
 * lands on it, and each answer keeps the watch open two more hours. A menu button or
 * «إلغاء» ends the watch; the «reply» button reopens it later. An email answer is read
 * from the inbox by its subject.
 */
class ComplaintTickets
{
    public const REPLY_CALLBACK = 'tkt:';

    private const WATCH_HOURS = 24;

    private const FOLLOW_HOURS = 2;

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
            $delivered = $this->sendBot($chatId, $complaint, $this->text($complaint, $body)."\n\nاكتب ردك هون مباشرة ليوصل للتذكرة.");
            $recorded = Client::isWhatsAppKey($chatId) ? 'whatsapp' : 'telegram';
            if ($delivered && $complaint->client !== null) {
                Cache::put($this->awaitKey($this->chatKey($complaint->client, $chatId)), $complaint->id, now()->addHours(self::WATCH_HOURS));
            }
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
        Cache::put($this->awaitKey($key), $complaint->id, now()->addHours(self::WATCH_HOURS));
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

    /** Files the client's text on the ticket the chat is watching. */
    public function clientReply(Client $client, string $chatId, string $key, string $text, string $lang): bool
    {
        $id = Cache::get($this->awaitKey($key));
        $complaint = Complaint::query()->whereKey((int) $id)->where('client_id', $client->id)->first();
        if (! $complaint instanceof Complaint) {
            $this->forget($key);

            return false;
        }

        $this->file($complaint, $client, Client::isWhatsAppKey($chatId) ? 'whatsapp' : 'telegram', $text, $chatId);
        Cache::put($this->awaitKey($key), $complaint->id, now()->addHours(self::FOLLOW_HOURS));
        // One receipt per burst, so a client typing three lines gets one answer.
        if (Cache::add('hoc:ticket-ack:'.$key, 1, now()->addMinutes(10))) {
            $this->say(
                $chatId,
                $lang,
                "وصل ردك على التذكرة {$complaint->ticket_number}. للرجوع للقائمة اضغط أي زر منها.",
                "Your reply reached ticket {$complaint->ticket_number}. Tap any menu button to go back.",
            );
        }

        return true;
    }

    /**
     * An email answer to a ticket. The subject names the ticket and the sender must be
     * the client's email, so a stranger cannot write on someone else's ticket.
     */
    public function emailReply(string $from, string $subject, string $body): bool
    {
        if (preg_match('/T-\d{5,}/', $subject, $match) !== 1) {
            return false;
        }
        $complaint = Complaint::query()->with('client')->where('ticket_number', $match[0])->first();
        $client = $complaint?->client;
        if (! $complaint instanceof Complaint || $client === null || strcasecmp(trim((string) $client->email), trim($from)) !== 0) {
            return false;
        }
        $text = self::withoutQuote($body);
        if ($text === '') {
            return false;
        }
        $this->file($complaint, $client, 'email', $text, null);

        return true;
    }

    /** The new part of an email answer, without the quoted ticket under it. */
    public static function withoutQuote(string $body): string
    {
        $kept = [];
        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $trimmed = trim($line);
            if (preg_match('/^(On .+wrote:|في .+كتب:?|.+ كتب:$|-{2,}\s*(Original Message|الرسالة الأصلية)|From:\s|من:\s|_{5,})/ui', $trimmed) === 1) {
                break;
            }
            if (str_starts_with($trimmed, '>')) {
                continue;
            }
            $kept[] = rtrim($line);
        }

        return mb_substr(trim(implode("\n", $kept)), 0, 4000);
    }

    private function file(Complaint $complaint, Client $client, string $channel, string $text, ?string $chatId): void
    {
        $complaint->messages()->create([
            'author' => ComplaintMessage::CLIENT,
            'channel' => $channel,
            'body' => mb_substr(trim($text), 0, 4000),
            'delivered' => true,
        ]);
        $complaint->forceFill(array_filter([
            'status' => Complaint::IN_PROGRESS,
            'chat_id' => $chatId,
        ]) + ['reviewed_at' => null])->save();

        $who = filled($client->company_name) ? (string) $client->company_name : (string) ($client->name ?: $client->phone);
        $this->notifyEmployees->handlePlain(
            EmployeeProfession::Sales,
            "ردّ {$who} على التذكرة {$complaint->ticket_number}: ".mb_substr(trim($text), 0, 500),
        );
    }

    /** The key the complaint conversation uses for this chat. */
    private function chatKey(Client $client, string $chatId): string
    {
        return Client::isWhatsAppKey($chatId) ? Client::whatsappPhoneFromKey($chatId) : 'tg:'.$client->id;
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
