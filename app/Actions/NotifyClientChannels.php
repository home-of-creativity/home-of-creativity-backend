<?php

namespace App\Actions;

use App\Models\ServiceRequest;
use App\Services\TelegramNotifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class NotifyClientChannels
{
    public function __construct(
        private TelegramNotifier $telegram,
    ) {}

    /**
     * True when the chat or the email accepted the message.
     *
     * @param  list<array{text: string, callback_data: string}>|null  $inlineButtons
     */
    public function send(ServiceRequest $request, string $text, ?array $inlineButtons = null): bool
    {
        $request->loadMissing('client');
        $preferred = Cache::get('hoc:client-reply:'.$request->client_id);
        $chatId = is_string($preferred) && $preferred !== ''
            ? $preferred
            : $request->client?->telegram_user_id;

        try {
            if (is_string($chatId) && $chatId !== '' && $this->telegram->canReachClient($chatId)) {
                if ($inlineButtons !== null && $inlineButtons !== []) {
                    $this->telegram->sendInlineActions($chatId, $text, $inlineButtons);
                } else {
                    $this->telegram->send($chatId, $text);
                }

                return true;
            }

            $email = $request->client?->email;
            if (filled($email)) {
                $from = (string) config('mail.mailers.reports.username');
                $mailer = filled(config('mail.mailers.reports.password')) ? 'reports' : (string) config('mail.default');
                Mail::mailer($mailer)->raw($text, function ($message) use ($email, $from): void {
                    $message->to((string) $email)->subject('Home of Creativity');
                    if ($from !== '') {
                        $message->from($from, 'Home of Creativity');
                    }
                });

                return true;
            }
        } catch (Throwable $exception) {
            Log::warning('Client channel notify failed.', [
                'request' => $request->number,
                'error' => $exception->getMessage(),
            ]);
        }

        return false;
    }
}
