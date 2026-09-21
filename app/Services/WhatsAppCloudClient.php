<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WhatsAppCloudClient
{
    public function configured(): bool
    {
        return filled(config('services.whatsapp.token'))
            && filled(config('services.whatsapp.phone_number_id'));
    }

    public function sendText(string $to, string $text): string
    {
        return $this->postMessage([
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $text],
        ]);
    }

    /**
     * @param  list<array{text: string, callback_data: string}>  $buttons
     */
    public function sendReplyButtons(string $to, string $text, array $buttons): string
    {
        $payloadButtons = [];
        foreach (array_slice($buttons, 0, 3) as $button) {
            $payloadButtons[] = [
                'type' => 'reply',
                'reply' => [
                    'id' => $this->clip((string) ($button['callback_data'] ?? ''), 256),
                    'title' => $this->clip((string) ($button['text'] ?? 'خيار'), 20),
                ],
            ];
        }

        if ($payloadButtons === []) {
            return $this->sendText($to, $text);
        }

        return $this->postMessage([
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => $this->clip($text, 1024)],
                'action' => ['buttons' => $payloadButtons],
            ],
        ]);
    }

    /**
     * @param  list<array{text: string, callback_data: string}>  $buttons
     */
    public function sendList(string $to, string $text, array $buttons): string
    {
        $rows = [];
        foreach (array_slice($buttons, 0, 10) as $button) {
            $rows[] = [
                'id' => $this->clip((string) ($button['callback_data'] ?? ''), 200),
                'title' => $this->clip((string) ($button['text'] ?? 'خيار'), 24),
            ];
        }

        if ($rows === []) {
            return $this->sendText($to, $text);
        }

        return $this->postMessage([
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'body' => ['text' => $this->clip($text, 1024)],
                'action' => [
                    'button' => 'الخيارات',
                    'sections' => [[
                        'title' => 'القائمة',
                        'rows' => $rows,
                    ]],
                ],
            ],
        ]);
    }

    public function sendDocument(string $to, string $absolutePath, ?string $caption = null, ?string $filename = null): string
    {
        $mediaId = $this->uploadMedia($absolutePath, mime_content_type($absolutePath) ?: 'application/octet-stream');
        $document = array_filter([
            'id' => $mediaId,
            'filename' => $filename ?: basename($absolutePath),
            'caption' => filled($caption) ? $this->clip((string) $caption, 1024) : null,
        ], fn ($value) => $value !== null && $value !== '');

        return $this->postMessage([
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'document',
            'document' => $document,
        ]);
    }

    public function sendImage(string $to, string $absolutePath, ?string $caption = null): string
    {
        $mediaId = $this->uploadMedia($absolutePath, mime_content_type($absolutePath) ?: 'image/jpeg');
        $image = array_filter([
            'id' => $mediaId,
            'caption' => filled($caption) ? $this->clip((string) $caption, 1024) : null,
        ], fn ($value) => $value !== null && $value !== '');

        return $this->postMessage([
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'image',
            'image' => $image,
        ]);
    }

    /**
     * @return array{binary: string, mime: string, filename: string}
     */
    public function downloadMedia(string $mediaId): array
    {
        $meta = $this->http()
            ->get($this->graphUrl($mediaId))
            ->throw();

        $url = (string) $meta->json('url');
        $mime = (string) ($meta->json('mime_type') ?: 'application/octet-stream');
        if ($url === '') {
            throw new RuntimeException('WhatsApp media URL is missing.');
        }

        $file = $this->http()
            ->withHeaders(['Accept' => '*/*'])
            ->timeout(30)
            ->get($url)
            ->throw();

        $binary = $file->body();
        if ($binary === '') {
            throw new RuntimeException('WhatsApp media download was empty.');
        }

        return [
            'binary' => $binary,
            'mime' => $mime,
            'filename' => $mediaId,
        ];
    }

    public function markRead(string $messageId): void
    {
        if ($messageId === '' || ! $this->configured()) {
            return;
        }

        try {
            $this->http()->post($this->messagesUrl(), [
                'messaging_product' => 'whatsapp',
                'status' => 'read',
                'message_id' => $messageId,
            ]);
        } catch (\Throwable $exception) {
            Log::info('WhatsApp mark-as-read skipped.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postMessage(array $payload): string
    {
        if (! $this->configured()) {
            throw new RuntimeException('WhatsApp Cloud API is not configured.');
        }

        $response = $this->http()
            ->retry(2, 200)
            ->post($this->messagesUrl(), $payload);

        if (! $response->successful()) {
            Log::warning('WhatsApp message send failed.', ['body' => $response->body()]);

            throw new RuntimeException($this->failureMessage($response, 'WhatsApp did not accept the message.'));
        }

        $id = $response->json('messages.0.id');

        return is_string($id) && $id !== '' ? $id : 'ok';
    }

    private function uploadMedia(string $absolutePath, string $mime): string
    {
        if (! is_file($absolutePath)) {
            throw new RuntimeException('WhatsApp media file not found.');
        }

        $handle = fopen($absolutePath, 'r');
        if ($handle === false) {
            throw new RuntimeException('WhatsApp media file could not be read.');
        }

        $response = $this->http()
            ->timeout(30)
            ->attach('file', $handle, basename($absolutePath))
            ->post($this->mediaUrl(), [
                'messaging_product' => 'whatsapp',
                'type' => $mime,
            ]);

        if (! $response->successful() || ! filled($response->json('id'))) {
            Log::warning('WhatsApp media upload failed.', ['body' => $response->body()]);

            throw new RuntimeException($this->failureMessage($response, 'WhatsApp did not accept the media upload.'));
        }

        return (string) $response->json('id');
    }

    private function http(): PendingRequest
    {
        return Http::timeout(10)
            ->connectTimeout(3)
            ->acceptJson()
            ->withToken((string) config('services.whatsapp.token'));
    }

    private function messagesUrl(): string
    {
        return $this->graphUrl((string) config('services.whatsapp.phone_number_id').'/messages');
    }

    private function mediaUrl(): string
    {
        return $this->graphUrl((string) config('services.whatsapp.phone_number_id').'/media');
    }

    private function graphUrl(string $path): string
    {
        $base = rtrim((string) config('services.whatsapp.graph_base', 'https://graph.facebook.com/v21.0'), '/');

        return $base.'/'.ltrim($path, '/');
    }

    private function failureMessage(Response $response, string $fallback): string
    {
        $candidates = [
            $response->json('error.message'),
            $response->json('error.error_user_msg'),
            $response->json('error.error_data.details'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return $fallback;
    }

    private function clip(string $value, int $max): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'خيار';
        }

        return mb_strlen($value) <= $max ? $value : mb_substr($value, 0, $max);
    }
}
