<?php

namespace App\Console\Commands;

use App\Actions\PushClientLeadToOdoo;
use App\Actions\PushClientToOdoo;
use App\Models\Client;
use Illuminate\Console\Command;

class PushClientToOdooCommand extends Command
{
    protected $signature = 'odoo:push-client {client}';

    protected $description = 'Push one client partner and Telegram CRM lead to Odoo.';

    public function handle(PushClientToOdoo $pushClientToOdoo, PushClientLeadToOdoo $pushClientLeadToOdoo): int
    {
        $client = Client::query()->find($this->argument('client'));
        if (! $client) {
            return self::SUCCESS;
        }

        $client = $pushClientToOdoo->handle($client);
        $pushClientLeadToOdoo->handle($client);

        return self::SUCCESS;
    }
}
