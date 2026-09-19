<?php

namespace App\Actions;

use App\Models\DriveDelivery;
use App\Models\ServiceRequest;
use App\Services\TelegramNotifier;
use Throwable;

class ClearDriveDeliveryKeyboards
{
    public function __construct(private TelegramNotifier $telegram) {}

    public function handle(ServiceRequest $request): void
    {
        $chatId = $request->client?->telegram_user_id;
        if (! filled($chatId) || ! $this->telegram->configured('client')) {
            return;
        }

        $deliveries = DriveDelivery::query()
            ->where('request_id', $request->id)
            ->whereNotNull('telegram_message_id')
            ->get();

        foreach ($deliveries as $delivery) {
            try {
                $this->telegram->editReplyMarkup(
                    (string) $chatId,
                    (int) $delivery->telegram_message_id,
                    ['inline_keyboard' => []],
                );
            } catch (Throwable) {
                // Stale Telegram messages are ignored.
            }
        }
    }
}
