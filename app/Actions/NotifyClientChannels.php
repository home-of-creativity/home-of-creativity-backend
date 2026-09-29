<?php

namespace App\Actions;

use App\Models\Client;
use App\Models\ServiceRequest;
use App\Services\TelegramNotifier;
use App\Services\WhatsAppCloudClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class NotifyClientChannels
{
    public function __construct(
        private TelegramNotifier $telegram,
        private WhatsAppCloudClient $whatsApp,
    ) {}

    /**
     * @param  list<array{text: string, callback_data: string}>|null  $inlineButtons
     */
    public function send(ServiceRequest $request, string $text, ?array $inlineButtons = null): void
    {
        $request->loadMissing('client');
        $chatId = $request->client?->telegram_user_id;

        try {
            if ($inlineButtons !== null && $inlineButtons !== [] && is_string($chatId) && $chatId !== ''
                && ($this->telegram->canReachClient($chatId) || (Client::isWhatsAppKey($chatId) && $this->whatsApp->configured()))) {
                $this->telegram->sendInlineActions($chatId, $text, $inlineButtons);

                return;
            }

            if (is_string($chatId) && Client::isWhatsAppKey($chatId) && $this->whatsApp->configured()) {
                $this->whatsApp->sendText(Client::whatsappPhoneFromKey($chatId), $text);

                return;
            }

            if ($this->telegram->canReachClient($chatId)) {
                $this->telegram->send((string) $chatId, $text);

                return;
            }

            $email = $request->client?->email;
            if (filled($email)) {
                Mail::raw($text, function ($message) use ($email): void {
                    $message->to((string) $email)->subject('Home of Creativity');
                });
            }
        } catch (Throwable $exception) {
            Log::warning('Client channel notify failed.', [
                'request' => $request->number,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
