<?php

namespace App\Support;

use App\Models\Client;
use App\Models\OpsSetting;

class ClientChannelGate
{
    public const TELEGRAM_KEY = 'client_telegram_enabled';

    public const WHATSAPP_KEY = 'client_whatsapp_enabled';

    public const TELEGRAM_PAUSED_MESSAGE = 'بوت تيليجرام متوقف مؤقتاً. يمكنك التواصل عبر واتساب، أو أعد المحاولة لاحقاً.';

    public const WHATSAPP_PAUSED_MESSAGE = 'بوت واتساب متوقف مؤقتاً. يمكنك التواصل عبر تيليجرام، أو أعد المحاولة لاحقاً.';

    public static function telegramEnabled(): bool
    {
        return self::flag(self::TELEGRAM_KEY);
    }

    public static function whatsappEnabled(): bool
    {
        return self::flag(self::WHATSAPP_KEY);
    }

    public static function enabledForChatId(mixed $chatId): bool
    {
        if (Client::isWhatsAppKey($chatId)) {
            return self::whatsappEnabled();
        }

        return self::telegramEnabled();
    }

    public static function setTelegramEnabled(bool $enabled): void
    {
        OpsSetting::setValue(self::TELEGRAM_KEY, $enabled ? '1' : '0');
    }

    public static function setWhatsAppEnabled(bool $enabled): void
    {
        OpsSetting::setValue(self::WHATSAPP_KEY, $enabled ? '1' : '0');
    }

    /**
     * @return array{telegram_enabled: bool, whatsapp_enabled: bool}
     */
    public static function payload(): array
    {
        return [
            'telegram_enabled' => self::telegramEnabled(),
            'whatsapp_enabled' => self::whatsappEnabled(),
        ];
    }

    private static function flag(string $key): bool
    {
        $value = strtolower(trim((string) OpsSetting::getValue($key, '1')));

        return ! in_array($value, ['0', 'false', 'off', 'no'], true);
    }
}
