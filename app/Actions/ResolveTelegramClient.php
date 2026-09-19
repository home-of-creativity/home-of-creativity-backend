<?php

namespace App\Actions;

use App\Models\Client;

class ResolveTelegramClient
{
    public function __construct(
        private PushClientToOdoo $pushClientToOdoo,
        private PushClientLeadToOdoo $pushClientLeadToOdoo,
    ) {}

    public function handle(?string $telegramUserId): Client
    {
        $client = Client::findForTelegram($telegramUserId);
        abort_if($client === null, 404);

        return $this->republishIfNeeded($client);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function link(string $telegramUserId, array $payload): Client
    {
        $client = Client::query()->withTrashed()->firstOrNew([
            'telegram_user_id' => $telegramUserId,
        ]);

        if ($client->exists && $client->trashed()) {
            $client->restore();
            $client = $client->fresh() ?? $client;
        }

        $client->fill(array_filter($payload, fn ($value) => $value !== null && $value !== ''))->save();
        $client = $client->fresh() ?? $client;

        if (! $client->readyForOdoo()) {
            return $client;
        }

        $client = $this->pushClientToOdoo->handle($client);
        $this->pushClientLeadToOdoo->handle(
            $client,
            writeExisting: filled($client->odoo_lead_id),
            classifyIndustry: false,
        );

        return $client->fresh() ?? $client;
    }

    private function republishIfNeeded(Client $client): Client
    {
        if (! $client->readyForOdoo() || filled($client->odoo_lead_id)) {
            return $client;
        }

        $client = $this->pushClientToOdoo->handle($client);
        $this->pushClientLeadToOdoo->handle($client, writeExisting: false, classifyIndustry: false);

        return $client->fresh() ?? $client;
    }
}
