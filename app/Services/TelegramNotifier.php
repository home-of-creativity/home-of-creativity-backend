<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ServiceRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class TelegramNotifier
{
    public ?int $lastMessageId = null;

    public function __construct(private WhatsAppCloudClient $whatsApp) {}

    public function configured(string $bot = 'client', mixed $chatId = null): bool
    {
        if (Client::isWhatsAppKey($chatId)) {
            return $this->whatsApp->configured();
        }

        return filled($this->token($bot));
    }

    public function canReachClient(mixed $chatId): bool
    {
        return filled($chatId) && $this->configured('client', $chatId);
    }

    public function send(string $chatId, string $text, string $bot = 'client'): void
    {
        if (Client::isWhatsAppKey($chatId)) {
            $this->whatsApp->sendText(Client::whatsappPhoneFromKey($chatId), $text);

            return;
        }

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

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function sendDocument(string $chatId, string $absolutePath, ?string $caption = null, string $bot = 'client', ?array $replyMarkup = null): ?string
    {
        if (Client::isWhatsAppKey($chatId)) {
            return $this->sendWhatsAppFile($chatId, $absolutePath, $caption, $replyMarkup, asImage: false);
        }

        $token = $this->token($bot);
        if ($token === '') {
            throw new RuntimeException('Telegram bot is not configured.');
        }

        if (! is_file($absolutePath)) {
            throw new RuntimeException('Document file not found.');
        }

        $fields = array_filter([
            'chat_id' => $chatId,
            'caption' => $caption,
        ], fn ($value) => $value !== null && $value !== '');
        if ($replyMarkup !== null) {
            $fields['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
        }

        $response = Http::timeout(30)
            ->connectTimeout(5)
            ->attach('document', fopen($absolutePath, 'r'), basename($absolutePath))
            ->post("https://api.telegram.org/bot{$token}/sendDocument", $fields);

        if (! $response->successful() || $response->json('ok') !== true) {
            Log::warning('Telegram document send failed.', ['body' => $response->body()]);

            throw new RuntimeException($this->failureMessage($response, 'Telegram did not accept the document.'));
        }

        $this->lastMessageId = $this->messageIdFrom($response->json());

        return data_get($response->json(), 'result.document.file_id');
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function sendPhoto(string $chatId, string $absolutePath, ?string $caption = null, string $bot = 'client', ?array $replyMarkup = null): ?string
    {
        if (Client::isWhatsAppKey($chatId)) {
            return $this->sendWhatsAppFile($chatId, $absolutePath, $caption, $replyMarkup, asImage: true);
        }

        $token = $this->token($bot);
        if ($token === '') {
            throw new RuntimeException('Telegram bot is not configured.');
        }

        if (! is_file($absolutePath)) {
            throw new RuntimeException('Photo file not found.');
        }

        $fields = array_filter([
            'chat_id' => $chatId,
            'caption' => $caption,
        ], fn ($value) => $value !== null && $value !== '');
        if ($replyMarkup !== null) {
            $fields['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
        }

        $response = Http::timeout(30)
            ->connectTimeout(5)
            ->attach('photo', fopen($absolutePath, 'r'), basename($absolutePath))
            ->post("https://api.telegram.org/bot{$token}/sendPhoto", $fields);

        if (! $response->successful() || $response->json('ok') !== true) {
            Log::warning('Telegram photo send failed.', ['body' => $response->body()]);

            throw new RuntimeException($this->failureMessage($response, 'Telegram did not accept the photo.'));
        }

        $this->lastMessageId = $this->messageIdFrom($response->json());

        $photoSizes = data_get($response->json(), 'result.photo');
        if (! is_array($photoSizes) || $photoSizes === []) {
            return null;
        }

        $largest = end($photoSizes);

        return is_array($largest) ? ($largest['file_id'] ?? null) : null;
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function sendFile(string $chatId, string $absolutePath, string $mimeType, ?string $caption = null, string $bot = 'client', ?array $replyMarkup = null): ?string
    {
        $mime = strtolower($mimeType);
        $isHeic = str_contains($mime, 'heic') || str_contains($mime, 'heif');
        if (str_starts_with($mime, 'image/') && ! $isHeic) {
            try {
                return $this->sendPhoto($chatId, $absolutePath, $caption, $bot, $replyMarkup);
            } catch (RuntimeException $exception) {
                Log::warning('Telegram photo send fell back to document.', [
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $this->sendDocument($chatId, $absolutePath, $caption, $bot, $replyMarkup);
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function editReplyMarkup(string $chatId, int $messageId, ?array $replyMarkup = null, string $bot = 'client'): void
    {
        if (Client::isWhatsAppKey($chatId)) {
            return;
        }

        $token = $this->token($bot);
        if ($token === '') {
            return;
        }

        $response = Http::timeout(10)
            ->connectTimeout(3)
            ->acceptJson()
            ->post("https://api.telegram.org/bot{$token}/editMessageReplyMarkup", [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'reply_markup' => $replyMarkup ?? ['inline_keyboard' => []],
            ]);

        if (! $response->successful() || $response->json('ok') !== true) {
            Log::info('Telegram keyboard clear skipped.', [
                'body' => $response->body(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function sendStoredDocument(ServiceRequest $request, string $relativePath, ?string $caption = null, ?array $replyMarkup = null): ?string
    {
        $chatId = $request->client?->telegram_user_id;
        if (! filled($chatId)) {
            return null;
        }

        $absolute = Storage::disk('local')->path($relativePath);

        return $this->sendDocument((string) $chatId, $absolute, $caption, 'client', $replyMarkup);
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

    /**
     * @param  list<array{text: string, callback_data: string}>  $buttons
     */
    public function sendInlineActions(string $chatId, string $text, array $buttons, string $bot = 'client'): void
    {
        if (Client::isWhatsAppKey($chatId)) {
            $this->sendWhatsAppInteractive(Client::whatsappPhoneFromKey($chatId), $text, $buttons);

            return;
        }

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
        if (Client::isWhatsAppKey($chatId)) {
            $this->sendWhatsAppInteractive(
                Client::whatsappPhoneFromKey($chatId),
                $text,
                $this->flattenButtons($rows),
            );

            return;
        }

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

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    private function sendWhatsAppFile(string $chatId, string $absolutePath, ?string $caption, ?array $replyMarkup, bool $asImage): ?string
    {
        $phone = Client::whatsappPhoneFromKey($chatId);
        $id = $asImage
            ? $this->whatsApp->sendImage($phone, $absolutePath, $caption)
            : $this->whatsApp->sendDocument($phone, $absolutePath, $caption, basename($absolutePath));

        $rows = [];
        if (is_array($replyMarkup)) {
            $rows = $replyMarkup['inline_keyboard'] ?? (array_is_list($replyMarkup) ? $replyMarkup : []);
        }
        $buttons = $this->flattenButtons(is_array($rows) ? $rows : []);
        if ($buttons !== []) {
            $this->sendWhatsAppInteractive($phone, 'اختر إجراء:', $buttons);
        }

        $this->lastMessageId = null;

        return $id;
    }

    /**
     * @param  list<array{text: string, callback_data: string}>  $buttons
     */
    private function sendWhatsAppInteractive(string $phone, string $text, array $buttons): void
    {
        if ($buttons === []) {
            $this->whatsApp->sendText($phone, $text);

            return;
        }

        if (count($buttons) <= 3) {
            $this->whatsApp->sendReplyButtons($phone, $text, $buttons);

            return;
        }

        $this->whatsApp->sendList($phone, $text, $buttons);
    }

    /**
     * @param  list<list<array{text: string, callback_data: string}>>|list<array{text: string, callback_data: string}>  $rows
     * @return list<array{text: string, callback_data: string}>
     */
    private function flattenButtons(array $rows): array
    {
        $buttons = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (isset($row['callback_data'])) {
                $buttons[] = $row;

                continue;
            }
            foreach ($row as $button) {
                if (is_array($button) && isset($button['callback_data'])) {
                    $buttons[] = $button;
                }
            }
        }

        return $buttons;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function messageIdFrom(mixed $payload): ?int
    {
        $id = data_get($payload, 'result.message_id');

        return is_numeric($id) ? (int) $id : null;
    }

    private function failureMessage(Response $response, string $fallback): string
    {
        $description = $response->json('description');

        return is_string($description) && $description !== '' ? $description : $fallback;
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
