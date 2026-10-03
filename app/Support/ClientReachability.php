<?php

namespace App\Support;

use App\Models\Client;
use App\Services\DevBeat;
use App\Services\TelegramNotifier;

class ClientReachability
{
    public const BOT_BEAT_MAX_AGE_SECONDS = 600;

    private ?int $clientBeatAge = null;

    private bool $beatRead = false;

    public function __construct(
        private TelegramNotifier $telegram,
        private DevBeat $beats,
    ) {}

    /**
     * True only when the client can press the bot buttons themselves: their
     * channel is open and, for Telegram, the client bot process is alive.
     */
    public function botReachable(?Client $client): bool
    {
        $chatId = $client?->telegram_user_id;
        if (! filled($chatId) || ! $this->telegram->canReachClient($chatId)) {
            return false;
        }

        if (Client::isWhatsAppKey($chatId)) {
            return true;
        }

        if (! $this->beatRead) {
            $this->clientBeatAge = $this->beats->ageSeconds('client');
            $this->beatRead = true;
        }

        return $this->clientBeatAge !== null && $this->clientBeatAge <= self::BOT_BEAT_MAX_AGE_SECONDS;
    }
}
