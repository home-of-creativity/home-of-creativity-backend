<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DevAlert
{
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
}
