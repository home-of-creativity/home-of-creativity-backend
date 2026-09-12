<?php

/**
 * Optional live Telegram E2E for quotation + invoice PDF delivery.
 *
 * Usage:
 *   E2E_TELEGRAM_CHAT_ID=123456789 php scripts/e2e-live-telegram.php
 *
 * Requirements:
 * - Client must have started /start with the bot (chat id from getUpdates).
 * - TELEGRAM_BOT_TOKEN and TELEGRAM_STRICT=false in .env
 * - Do NOT run in CI.
 */

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$chatId = (string) getenv('E2E_TELEGRAM_CHAT_ID');
if ($chatId === '') {
    fwrite(STDERR, "Set E2E_TELEGRAM_CHAT_ID to a chat that linked the bot.\n");
    exit(1);
}

$api = rtrim((string) config('app.url'), '/').'/api';
$botSecret = (string) config('services.telegram.bot_secret', 'change-me-bot');
$n8nSecret = (string) config('services.n8n.webhook_secret', 'change-me');

Http::withHeaders(['X-Webhook-Secret' => $botSecret])
    ->post("{$api}/bot/telegram/link", [
        'telegram_user_id' => $chatId,
        'name' => 'Live Telegram E2E',
        'locale' => 'ar',
    ])->throw();

$created = Http::withHeaders(['X-Webhook-Secret' => $botSecret])
    ->post("{$api}/bot/telegram/requests", [
        'telegram_user_id' => $chatId,
        'title' => 'Live Telegram PDF test',
        'description' => 'Quotation and invoice PDF delivery check.',
    ])->throw()
    ->json('data');

$number = (string) $created['number'];
$requestId = (int) $created['id'];

echo "Created {$number}\n";

$admin = Http::post("{$api}/auth/login", [
    'email' => 'admin@example.com',
    'password' => 'password',
])->throw()->json('data.token');

Http::withToken($admin)
    ->post("{$api}/admin/requests/{$requestId}/quotation", [
        'amount' => 1500,
        'notes' => 'Live Telegram quotation PDF',
    ])->throw();

echo "Quotation sent to Telegram chat {$chatId}\n";

Http::withHeaders(['X-Webhook-Secret' => $botSecret])
    ->post("{$api}/bot/telegram/requests/{$number}/approve", [
        'telegram_user_id' => $chatId,
    ])->throw();

Http::withToken($admin)
    ->post("{$api}/admin/requests/{$requestId}/confirm-payment", [
        'payment_method' => 'cash',
    ])->throw();

Http::withHeaders(['X-N8N-Secret' => $n8nSecret])
    ->post("{$api}/integrations/odoo/invoice", [
        'request_number' => $number,
        'odoo_partner_id' => 'P-'.$number,
        'odoo_quotation_id' => 'Q-'.$number,
    ])->throw();

echo "Live Telegram E2E finished for {$number}. Check the chat for PDFs.\n";
