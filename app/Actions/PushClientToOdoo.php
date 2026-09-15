<?php

namespace App\Actions;

use App\Models\Client;
use App\Services\OdooClient;
use Illuminate\Support\Facades\Log;

class PushClientToOdoo
{
    public function __construct(private OdooClient $odoo) {}

    public function handle(Client $client): Client
    {
        if (! $this->odoo->configured() || filled($client->odoo_partner_id)) {
            return $client;
        }

        try {
            $partnerId = $this->odoo->createOrReusePartner(
                $client->name,
                $client->email,
                $client->phone,
            );
            $client->forceFill(['odoo_partner_id' => $partnerId])->save();
        } catch (\Throwable $exception) {
            Log::warning('Odoo partner create failed for client.', [
                'client_id' => $client->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return $client->fresh() ?? $client;
    }
}
