<?php

namespace App\Actions;

use App\Models\Client;
use App\Services\OdooClient;
use Illuminate\Support\Facades\Log;

class PushClientToOdoo
{
    public function __construct(private OdooClient $odoo) {}

    public function handle(Client $client, bool $writeExisting = false): Client
    {
        if (! $this->odoo->configured()) {
            return $client;
        }

        try {
            $partnerName = filled($client->company_name) ? (string) $client->company_name : $client->name;
            $values = array_filter([
                'name' => $partnerName,
                'email' => $client->email,
                'phone' => $client->phone,
                'customer_rank' => 1,
            ], fn (mixed $value): bool => $value !== null && $value !== '');

            if (filled($client->odoo_partner_id)) {
                if ($writeExisting) {
                    $this->odoo->writeRecord('res.partner', (string) $client->odoo_partner_id, $values);
                }

                return $client->fresh() ?? $client;
            }

            $partnerId = $this->odoo->createOrReusePartner(
                $partnerName,
                $client->email,
                $client->phone,
            );
            $client->forceFill(['odoo_partner_id' => $partnerId])->save();
        } catch (\Throwable $exception) {
            Log::warning('Odoo partner sync failed for client.', [
                'client_id' => $client->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return $client->fresh() ?? $client;
    }
}
