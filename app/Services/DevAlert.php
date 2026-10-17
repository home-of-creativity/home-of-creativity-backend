<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DevAlert
{
    public function once(string $key, string $text, int $minutes = 360): void
    {
        if (! Cache::add($this->key($key), true, now()->addMinutes($minutes))) {
            return;
        }

        $this->send($text);
    }

    public function recover(string $key, string $text): void
    {
        if (! Cache::pull($this->key($key))) {
            return;
        }

        $this->send($text);
    }

    /**
     * Tell HOC DEV the WhatsApp bridge was restarted. Once per half hour so
     * the repair action and the health watch do not both ping.
     */
    public function bridgeRepaired(): void
    {
        if (! Cache::add('dev.wa.fixed-note', true, now()->addMinutes(30))) {
            return;
        }

        $this->send('تم إصلاح جسر واتساب. لا تُرسل هذه الرسالة للعميل.');
    }

    public function send(string $text): void
    {
        $token = (string) config('services.telegram.dev_bot_token');
        $chatId = (string) config('services.telegram.dev_chat_id');
        if ($token === '' || $chatId === '') {
            return;
        }

        try {
            Http::timeout(8)
                ->asForm()
                ->post('https://api.telegram.org/bot'.$token.'/sendMessage', [
                    'chat_id' => $chatId,
                    'text' => mb_substr($text, 0, 3500),
                    'disable_web_page_preview' => true,
                ])
                ->throw();
        } catch (\Throwable $exception) {
            Log::error('Developer Telegram alert failed.', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function key(string $key): string
    {
        return 'dev.alert.'.preg_replace('/[^a-zA-Z0-9_.:-]/', '', $key);
    }
}
