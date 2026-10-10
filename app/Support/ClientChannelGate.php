<?php

namespace App\Support;

use App\Models\Client;
use App\Models\OpsSetting;

class ClientChannelGate
{
    public const TELEGRAM_KEY = 'client_telegram_enabled';

    public const WHATSAPP_KEY = 'client_whatsapp_enabled';

    public const SUPPORT_PHONE = '0947823488';

    public const TELEGRAM_PAUSED_MESSAGE = 'بوت تيليجرام متوقف مؤقتاً. يمكنك التواصل عبر واتساب، أو أعد المحاولة لاحقاً.';

    public const TELEGRAM_PAUSED_PHONE_MESSAGE = 'بوت تيليجرام متوقف مؤقتاً. أعد المحاولة لاحقاً، أو اتصل على '.self::SUPPORT_PHONE.'.';

    public const WHATSAPP_PAUSED_MESSAGE = 'الواتساب واقف شوي هلق. جرب بعدين.';

    public static function telegramEnabled(): bool
    {
        return self::flag(self::TELEGRAM_KEY);
    }

    /**
     * Cloud API stays closed until WHATSAPP_ENABLED is set.
     * WhatsApp Web is open once WHATSAPP_TRANSPORT=web and the bridge URL is set.
     */
    public static function whatsappLocked(): bool
    {
        if (self::usesWhatsAppWeb()) {
            return ! filled(config('services.whatsapp.web_url'));
        }

        return ! filter_var(config('services.whatsapp.enabled', false), FILTER_VALIDATE_BOOL);
    }

    public static function usesWhatsAppWeb(): bool
    {
        return config('services.whatsapp.transport') === 'web';
    }

    public static function whatsappEnabled(): bool
    {
        return ! self::whatsappLocked() && self::flag(self::WHATSAPP_KEY);
    }

    public static function enabledForChatId(mixed $chatId): bool
    {
        if (Client::isWhatsAppKey($chatId)) {
            return self::whatsappEnabled();
        }

        return self::telegramEnabled();
    }

    public static function telegramPausedMessage(): string
    {
        return self::whatsappEnabled() ? self::TELEGRAM_PAUSED_MESSAGE : self::TELEGRAM_PAUSED_PHONE_MESSAGE;
    }

    public static function setTelegramEnabled(bool $enabled): void
    {
        OpsSetting::setValue(self::TELEGRAM_KEY, $enabled ? '1' : '0');
    }

    public static function setWhatsAppEnabled(bool $enabled): void
    {
        if (self::whatsappLocked()) {
            return;
        }

        OpsSetting::setValue(self::WHATSAPP_KEY, $enabled ? '1' : '0');
    }

    /**
     * @return array{telegram_enabled: bool, whatsapp_enabled: bool, whatsapp_locked: bool, whatsapp_transport: string}
     */
    public static function payload(): array
    {
        return [
            'telegram_enabled' => self::telegramEnabled(),
            'whatsapp_enabled' => self::whatsappEnabled(),
            'whatsapp_locked' => self::whatsappLocked(),
            'whatsapp_transport' => self::usesWhatsAppWeb() ? 'web' : 'cloud',
        ];
    }

    private static function flag(string $key): bool
    {
        $value = strtolower(trim((string) OpsSetting::getValue($key, '1')));

        return ! in_array($value, ['0', 'false', 'off', 'no'], true);
    }
}
