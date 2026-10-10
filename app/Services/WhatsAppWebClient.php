<?php

namespace App\Services;

use App\Contracts\WhatsAppMessenger;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WhatsAppWebClient implements WhatsAppMessenger
{
    public function configured(): bool
    {
        return $this->baseUrl() !== '' && $this->secret() !== '';
    }

    /**
     * Drop the linked phone so the next status poll can show a fresh scan code.
     *
     * @return array{connected: bool, qr: string|null, phone: string|null, reachable: bool}
     */
    public function unlink(): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('WhatsApp Web bridge is not configured.');
        }

        $response = $this->http()->post($this->baseUrl().'/logout');
        if (! $response->successful()) {
            throw new RuntimeException($this->failureMessage($response));
        }

        return $this->status();
    }

    /**
     * @return array{connected: bool, qr: string|null, phone: string|null, reachable: bool}
     */
    public function adminStatus(): array
    {
        return $this->sessionStatus('/admin/status');
    }

    /**
     * @return array{connected: bool, qr: string|null, phone: string|null, reachable: bool}
     */
    public function unlinkAdmin(): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('WhatsApp Web bridge is not configured.');
        }

        $response = $this->http()->post($this->baseUrl().'/admin/logout');
        if (! $response->successful()) {
            throw new RuntimeException($this->failureMessage($response));
        }

        return $this->adminStatus();
    }

    /**
     * @return array{connected: bool, qr: string|null, phone: string|null, reachable: bool}
     */
    public function status(): array
    {
        if (! $this->configured()) {
            return ['connected' => false, 'qr' => null, 'phone' => null, 'reachable' => false];
        }

        try {
            $response = $this->http()->get($this->baseUrl().'/status');
        } catch (\Throwable $exception) {
            Log::info('WhatsApp Web bridge is not reachable.', ['error' => $exception->getMessage()]);

            return ['connected' => false, 'qr' => null, 'phone' => null, 'reachable' => false];
        }

        if (! $response->successful()) {
            return ['connected' => false, 'qr' => null, 'phone' => null, 'reachable' => false];
        }

        return $this->sessionFromResponse($response);
    }

    /**
     * @return array{connected: bool, qr: string|null, phone: string|null, reachable: bool}
     */
    private function sessionStatus(string $path): array
    {
        if (! $this->configured()) {
            return ['connected' => false, 'qr' => null, 'phone' => null, 'reachable' => false];
        }

        try {
            $response = $this->http()->get($this->baseUrl().$path);
        } catch (\Throwable $exception) {
            Log::info('WhatsApp Web bridge is not reachable.', ['error' => $exception->getMessage()]);

            return ['connected' => false, 'qr' => null, 'phone' => null, 'reachable' => false];
        }

        if (! $response->successful()) {
            return ['connected' => false, 'qr' => null, 'phone' => null, 'reachable' => false];
        }

        return $this->sessionFromResponse($response);
    }

    /**
     * @return array{connected: bool, qr: string|null, phone: string|null, reachable: bool}
     */
    private function sessionFromResponse(Response $response): array
    {
        $qr = $response->json('qr');
        $phone = preg_replace('/\D+/', '', (string) $response->json('phone')) ?? '';

        return [
            'connected' => $response->json('connected') === true,
            'qr' => is_string($qr) && str_starts_with($qr, 'data:image/') ? $qr : null,
            'phone' => $phone !== '' ? $phone : null,
            'reachable' => true,
        ];
    }

    public function sendText(string $to, string $text): string
    {
        return $this->send([
            'to' => $to,
            'kind' => 'text',
            'text' => $text,
        ]);
    }

    /**
     * @param  list<array{text: string, callback_data: string}>  $buttons
     */
    public function sendReplyButtons(string $to, string $text, array $buttons): string
    {
        return $this->sendChoice($to, $text, $buttons);
    }

    /**
     * @param  list<array{text: string, callback_data: string}>  $buttons
     */
    public function sendList(string $to, string $text, array $buttons): string
    {
        return $this->sendChoice($to, $text, $buttons);
    }

    public function sendDocument(string $to, string $absolutePath, ?string $caption = null, ?string $filename = null): string
    {
        return $this->sendFile($to, $absolutePath, 'document', $caption, $filename ?: basename($absolutePath));
    }

    public function sendImage(string $to, string $absolutePath, ?string $caption = null): string
    {
        return $this->sendFile($to, $absolutePath, 'image', $caption, basename($absolutePath));
    }

    public function downloadMedia(string $mediaId): array
    {
        throw new RuntimeException('WhatsApp Web media is delivered with the inbound message.');
    }

    public function markRead(string $messageId): void
    {
    }

    /**
     * @param  list<array{text: string, callback_data: string}>  $buttons
     */
    private function sendChoice(string $to, string $text, array $buttons): string
    {
        $rows = [];
        foreach (array_slice($buttons, 0, 10) as $button) {
            $id = trim((string) ($button['callback_data'] ?? ''));
            $title = trim((string) ($button['text'] ?? ''));
            if ($id === '' || $title === '') {
                continue;
            }
            $rows[] = ['id' => $id, 'title' => $title];
        }

        if ($rows === []) {
            return $this->sendText($to, $text);
        }

        return $this->send([
            'to' => $to,
            'kind' => 'list',
            'text' => $text,
            'buttons' => $rows,
        ]);
    }

    private function sendFile(string $to, string $absolutePath, string $kind, ?string $caption, string $filename): string
    {
        if (! is_file($absolutePath)) {
            throw new RuntimeException('WhatsApp media file not found.');
        }

        $binary = file_get_contents($absolutePath);
        if ($binary === false || $binary === '') {
            throw new RuntimeException('WhatsApp media file could not be read.');
        }

        return $this->send([
            'to' => $to,
            'kind' => $kind,
            'caption' => $caption,
            'filename' => $filename,
            'mime' => mime_content_type($absolutePath) ?: 'application/octet-stream',
            'data_base64' => base64_encode($binary),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(array $payload): string
    {
        if (! $this->configured()) {
            throw new RuntimeException('WhatsApp Web bridge is not configured.');
        }

        $response = $this->http()
            ->retry(2, 200)
            ->post($this->baseUrl().'/send', $payload);

        if (! $response->successful()) {
            Log::warning('WhatsApp Web send failed.', ['status' => $response->status()]);

            throw new RuntimeException($this->failureMessage($response));
        }

        $id = $response->json('id');

        return is_string($id) && $id !== '' ? $id : 'ok';
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout(25)
            ->connectTimeout(3)
            ->acceptJson()
            ->withHeaders(['X-Webhook-Secret' => $this->secret()]);
    }

    private function baseUrl(): string
    {
        return rtrim(trim((string) config('services.whatsapp.web_url')), '/');
    }

    private function secret(): string
    {
        $secret = trim((string) config('services.whatsapp.web_secret'));

        return $secret !== '' ? $secret : trim((string) config('services.telegram.bot_secret'));
    }

    private function failureMessage(Response $response): string
    {
        $message = $response->json('message');

        return is_string($message) && trim($message) !== ''
            ? trim($message)
            : 'WhatsApp Web did not accept the message.';
    }
}
