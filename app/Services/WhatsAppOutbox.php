<?php

namespace App\Services;

use App\Contracts\WhatsAppMessenger;
use App\Models\Client;
use App\Models\WhatsAppOutboxMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The last messages each WhatsApp chat received, so a client can ask for them again.
 */
class WhatsAppOutbox
{
    public const KEEP = 10;

    public const MAX_RESEND = 3;

    private const DIR = 'whatsapp-outbox';

    private const MAX_FILE_BYTES = 16 * 1024 * 1024;

    private static bool $muted = false;

    /**
     * @param  list<array{text: string, callback_data: string}>  $buttons
     */
    public static function record(string $to, string $kind, ?string $body, array $buttons = [], ?string $absolutePath = null, ?string $filename = null): void
    {
        $recipient = Client::normalizeWhatsAppPhone($to);
        if (self::$muted || $recipient === '') {
            return;
        }

        try {
            $stored = null;
            if ($absolutePath !== null) {
                $stored = self::keepCopy($absolutePath);
                if ($stored === null) {
                    return;
                }
            }

            WhatsAppOutboxMessage::query()->create([
                'recipient' => $recipient,
                'kind' => $kind,
                'body' => $body,
                'buttons' => $buttons !== [] ? array_values($buttons) : null,
                'file_path' => $stored,
                'filename' => $filename,
            ]);
            self::prune($recipient);
        } catch (Throwable $exception) {
            Log::info('WhatsApp outbox could not record a message.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * How many recent messages the text asks to get again, or null when it asks for something else.
     */
    public static function requestedCount(string $text): ?int
    {
        $text = strtr(trim($text), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        if ($text === '' || mb_strlen($text) > 120) {
            return null;
        }

        $again = '/(?:[اأإ]?رجّ?ع|[اأإ]رجاع|[اأإ]?عيد|[اأإ]عد|[اأإ]عاد[ةه]|عاود|[اأإ]?بع[تث]|[اأإ]رسل|resend|send|again)/ui';
        $last = '/(?:(?<!\p{L})[آا]خر|\blast\b|\brecent\b)/ui';
        $message = '/(?:رسال|رسائل|مسج|ماسج|messages?|msgs?)/ui';
        if (preg_match($again, $text) !== 1 || preg_match($last, $text) !== 1 || preg_match($message, $text) !== 1) {
            return null;
        }

        if (preg_match('/\b(\d{1,3})\b/u', $text, $match) === 1) {
            return max(1, (int) $match[1]);
        }
        if (preg_match('/(?:ثل[اث]ث|تل[اث]ت|three)/ui', $text) === 1) {
            return 3;
        }
        if (preg_match('/(?:رسالتين|رسالتان|[اإ]ثن[تي]ين|تنتين|تنين|two)/ui', $text) === 1) {
            return 2;
        }
        if (preg_match('/(?:رسائل|مسجات|ماسجات|messages|msgs)/ui', $text) === 1) {
            return self::MAX_RESEND;
        }

        return 1;
    }

    /**
     * Sends the chat's last messages again, oldest first, without recording them a second time.
     *
     * @return int how many were sent
     */
    public static function resend(WhatsAppMessenger $whatsApp, string $to, int $count): int
    {
        $recipient = Client::normalizeWhatsAppPhone($to);
        $count = max(1, min(self::MAX_RESEND, $count));
        $messages = WhatsAppOutboxMessage::query()
            ->where('recipient', $recipient)
            ->orderByDesc('id')
            ->limit($count)
            ->get()
            ->reverse()
            ->values();

        $sent = 0;
        self::quietly(function () use ($whatsApp, $to, $messages, &$sent): void {
            foreach ($messages as $message) {
                if (self::deliver($whatsApp, $to, $message)) {
                    $sent++;
                }
            }
        });

        return $sent;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function quietly(callable $callback): mixed
    {
        $was = self::$muted;
        self::$muted = true;
        try {
            return $callback();
        } finally {
            self::$muted = $was;
        }
    }

    private static function deliver(WhatsAppMessenger $whatsApp, string $to, WhatsAppOutboxMessage $message): bool
    {
        $body = (string) ($message->body ?? '');
        try {
            switch ($message->kind) {
                case 'choice':
                    $whatsApp->sendList($to, $body, is_array($message->buttons) ? $message->buttons : []);
                    break;
                case 'document':
                case 'image':
                    $path = $message->file_path !== null ? Storage::disk('local')->path($message->file_path) : '';
                    if ($path === '' || ! is_file($path)) {
                        if ($body === '') {
                            return false;
                        }
                        $whatsApp->sendText($to, $body);
                        break;
                    }
                    $message->kind === 'image'
                        ? $whatsApp->sendImage($to, $path, $body !== '' ? $body : null)
                        : $whatsApp->sendDocument($to, $path, $body !== '' ? $body : null, $message->filename);
                    break;
                default:
                    if ($body === '') {
                        return false;
                    }
                    $whatsApp->sendText($to, $body);
            }
        } catch (Throwable $exception) {
            Log::info('WhatsApp outbox could not resend a message.', ['id' => $message->id, 'error' => $exception->getMessage()]);

            return false;
        }

        return true;
    }

    private static function keepCopy(string $absolutePath): ?string
    {
        if (! is_file($absolutePath)) {
            return null;
        }
        $size = filesize($absolutePath);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            return null;
        }
        $binary = file_get_contents($absolutePath);
        if ($binary === false || $binary === '') {
            return null;
        }

        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        $relative = self::DIR.'/'.Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');
        Storage::disk('local')->put($relative, $binary);

        return $relative;
    }

    private static function prune(string $recipient): void
    {
        $stale = WhatsAppOutboxMessage::query()
            ->where('recipient', $recipient)
            ->orderByDesc('id')
            ->skip(self::KEEP)
            ->take(100)
            ->get(['id', 'file_path']);
        if ($stale->isEmpty()) {
            return;
        }

        $files = $stale->pluck('file_path')->filter()->values()->all();
        if ($files !== []) {
            Storage::disk('local')->delete($files);
        }
        WhatsAppOutboxMessage::query()->whereIn('id', $stale->pluck('id'))->delete();
    }
}
