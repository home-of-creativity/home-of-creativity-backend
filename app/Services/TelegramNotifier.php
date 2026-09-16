<?php

namespace App\Services;

use App\Models\ServiceRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class TelegramNotifier
{
    public function configured(string $bot = 'client'): bool
    {
        return filled($this->token($bot));
    }

    public function send(string $chatId, string $text, string $bot = 'client'): void
    {
        $token = $this->token($bot);
        if ($token === '') {
            throw new RuntimeException('Telegram bot is not configured.');
        }

        $response = Http::timeout(10)
            ->connectTimeout(3)
            ->retry(2, 200)
            ->acceptJson()
            ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
            ]);

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new RuntimeException('Telegram did not accept the message.');
        }
    }

    public function sendDocument(string $chatId, string $absolutePath, ?string $caption = null, string $bot = 'client'): ?string
    {
        $token = $this->token($bot);
        if ($token === '') {
            throw new RuntimeException('Telegram bot is not configured.');
        }

        if (! is_file($absolutePath)) {
            throw new RuntimeException('Document file not found.');
        }

        $response = Http::timeout(30)
            ->connectTimeout(5)
            ->attach('document', fopen($absolutePath, 'r'), basename($absolutePath))
            ->post("https://api.telegram.org/bot{$token}/sendDocument", array_filter([
                'chat_id' => $chatId,
                'caption' => $caption,
            ]));

        if (! $response->successful() || $response->json('ok') !== true) {
            Log::warning('Telegram document send failed.', ['body' => $response->body()]);

            throw new RuntimeException('Telegram did not accept the document.');
        }

        return data_get($response->json(), 'result.document.file_id');
    }

    public function sendPhoto(string $chatId, string $absolutePath, ?string $caption = null, string $bot = 'client'): ?string
    {
        $token = $this->token($bot);
        if ($token === '') {
            throw new RuntimeException('Telegram bot is not configured.');
        }

        if (! is_file($absolutePath)) {
            throw new RuntimeException('Photo file not found.');
        }

        $response = Http::timeout(30)
            ->connectTimeout(5)
            ->attach('photo', fopen($absolutePath, 'r'), basename($absolutePath))
            ->post("https://api.telegram.org/bot{$token}/sendPhoto", array_filter([
                'chat_id' => $chatId,
                'caption' => $caption,
            ]));

        if (! $response->successful() || $response->json('ok') !== true) {
            Log::warning('Telegram photo send failed.', ['body' => $response->body()]);

            throw new RuntimeException('Telegram did not accept the photo.');
        }

        $photoSizes = data_get($response->json(), 'result.photo');
        if (! is_array($photoSizes) || $photoSizes === []) {
            return null;
        }

        $largest = end($photoSizes);

        return is_array($largest) ? ($largest['file_id'] ?? null) : null;
    }

    public function sendFile(string $chatId, string $absolutePath, string $mimeType, ?string $caption = null, string $bot = 'client'): ?string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return $this->sendPhoto($chatId, $absolutePath, $caption, $bot);
        }

        return $this->sendDocument($chatId, $absolutePath, $caption, $bot);
    }

    public function sendStoredDocument(ServiceRequest $request, string $relativePath, ?string $caption = null): ?string
    {
        $chatId = $request->client?->telegram_user_id;
        if (! filled($chatId)) {
            return null;
        }

        $absolute = Storage::disk('local')->path($relativePath);

        return $this->sendDocument((string) $chatId, $absolute, $caption, 'client');
    }

    public function sendPaymentQr(string $chatId, string $caption, string $relativePath, string $bot = 'client'): ?string
    {
        $absolute = Storage::disk('local')->path($relativePath);
        if (! is_file($absolute)) {
            $this->send($chatId, $caption, $bot);

            return null;
        }

        $mime = mime_content_type($absolute) ?: 'image/png';

        return $this->sendFile($chatId, $absolute, $mime, $caption, $bot);
    }

    public function sendInlineActions(string $chatId, string $text, array $buttons, string $bot = 'client'): void
    {
        $token = $this->token($bot);
        if ($token === '') {
            throw new RuntimeException('Telegram bot is not configured.');
        }

        $response = Http::timeout(10)
            ->connectTimeout(3)
            ->retry(2, 200)
            ->acceptJson()
            ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'reply_markup' => [
                    'inline_keyboard' => [$buttons],
                ],
            ]);

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new RuntimeException('Telegram did not accept the inline message.');
        }
    }

    /**
     * @param  list<list<array{text: string, callback_data: string}>>  $rows
     */
    public function sendInlineKeyboard(string $chatId, string $text, array $rows, string $bot = 'client'): void
    {
        $token = $this->token($bot);
        if ($token === '') {
            throw new RuntimeException('Telegram bot is not configured.');
        }

        $response = Http::timeout(10)
            ->connectTimeout(3)
            ->retry(2, 200)
            ->acceptJson()
            ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'reply_markup' => [
                    'inline_keyboard' => $rows,
                ],
            ]);

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new RuntimeException('Telegram did not accept the inline message.');
        }
    }

    private function token(string $bot): string
    {
        return match ($bot) {
            'staff' => (string) config('services.telegram.staff_bot_token'),
            'admin' => (string) config('services.telegram.admin_bot_token'),
            default => (string) config('services.telegram.bot_token'),
        };
    }
}
