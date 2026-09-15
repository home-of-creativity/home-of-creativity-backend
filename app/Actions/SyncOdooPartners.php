<?php

namespace App\Actions;

use App\Models\Client;
use App\Services\OdooClient;
use Illuminate\Support\Facades\Log;

class SyncOdooPartners
{
    public function __construct(
        private OdooClient $odoo,
        private PushClientToOdoo $pushClientToOdoo,
    ) {}

    /**
     * @return array{synced: int, created: int, updated: int, pushed: int}
     */
    public function handle(int $limit = 200): array
    {
        if (! $this->odoo->configured()) {
            return ['synced' => 0, 'created' => 0, 'updated' => 0, 'pushed' => 0];
        }

        $partners = $this->odoo->listPartners($limit);
        $created = 0;
        $updated = 0;

        foreach ($partners as $partner) {
            $odooId = (string) $partner['id'];
            $client = Client::query()->where('odoo_partner_id', $odooId)->first();

            if (! $client && filled($partner['email'])) {
                $client = Client::query()->where('email', $partner['email'])->first();
            }

            $payload = [
                'name' => $partner['name'],
                'email' => $partner['email'],
                'phone' => $partner['phone'],
                'odoo_partner_id' => $odooId,
            ];

            if ($client) {
                $client->fill($payload)->save();
                $updated++;

                continue;
            }

            try {
                Client::query()->create($payload);
                $created++;
            } catch (\Throwable $exception) {
                Log::warning('Odoo partner sync skipped a row.', [
                    'odoo_partner_id' => $odooId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $pushed = 0;
        Client::query()
            ->whereNull('odoo_partner_id')
            ->each(function (Client $client) use (&$pushed): void {
                $this->pushClientToOdoo->handle($client);
                if (filled($client->fresh()?->odoo_partner_id)) {
                    $pushed++;
                }
            });

        return [
            'synced' => count($partners),
            'created' => $created,
            'updated' => $updated,
            'pushed' => $pushed,
        ];
    }
}
