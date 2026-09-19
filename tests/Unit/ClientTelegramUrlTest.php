<?php

namespace Tests\Unit;

use App\Models\Client;
use Tests\TestCase;

class ClientTelegramUrlTest extends TestCase
{
    public function test_numeric_telegram_id_builds_private_chat_url(): void
    {
        $client = new Client(['telegram_user_id' => '213309826']);

        $this->assertSame('tg://user?id=213309826', $client->telegramPrivateUrl());
        $this->assertSame('تواصل خاص: tg://user?id=213309826', $client->telegramContactLine());
    }

    public function test_placeholder_telegram_id_has_no_private_chat_url(): void
    {
        $client = new Client(['telegram_user_id' => 'tg-client-9']);

        $this->assertNull($client->telegramPrivateUrl());
        $this->assertNull($client->telegramContactLine());
    }
}
