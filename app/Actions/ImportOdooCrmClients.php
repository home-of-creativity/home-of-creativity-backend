<?php

namespace App\Actions;

use App\Models\Client;
use App\Services\OdooClient;
use Illuminate\Support\Facades\Log;

class ImportOdooCrmClients
{
    public function __construct(
        private OdooClient $odoo,
        private SyncOdooPartners $syncOdooPartners,
    ) {}

    /**
     * @return array{imported: int, created: int, updated: int, pushed: int, crm_leads: int, partners: int}
     */
    public function handle(int $limit = 200): array
    {
        if (! $this->odoo->configured()) {
            return [
                'imported' => 0,
                'created' => 0,
                'updated' => 0,
                'pushed' => 0,
                'crm_leads' => 0,
                'partners' => 0,
            ];
        }

        $fromCrm = $this->importFromCrmLeads($limit);
        $fromPartners = $this->syncOdooPartners->handle($limit);

        return [
            'imported' => $fromCrm['created'] + $fromCrm['updated'] + $fromPartners['created'] + $fromPartners['updated'],
            'created' => $fromCrm['created'] + $fromPartners['created'],
            'updated' => $fromCrm['updated'] + $fromPartners['updated'],
            'pushed' => $fromPartners['pushed'],
            'crm_leads' => $fromCrm['synced'],
            'partners' => $fromPartners['synced'],
        ];
    }

    /**
     * @return array{synced: int, created: int, updated: int}
     */
    private function importFromCrmLeads(int $limit): array
    {
        $leads = $this->odoo->listCrmClients($limit);
        $created = 0;
        $updated = 0;

        foreach ($leads as $lead) {
            $result = $this->upsertClient(
                name: $lead['name'],
                email: $lead['email'],
                phone: $lead['phone'],
                odooPartnerId: $lead['odoo_partner_id'],
            );

            if ($result === 'created') {
                $created++;
            } elseif ($result === 'updated') {
                $updated++;
            }
        }

        return [
            'synced' => count($leads),
            'created' => $created,
            'updated' => $updated,
        ];
    }

    private function upsertClient(string $name, ?string $email, ?string $phone, ?string $odooPartnerId): ?string
    {
        $client = null;

        if (filled($odooPartnerId)) {
            $client = Client::query()->where('odoo_partner_id', $odooPartnerId)->first();
        }

        if (! $client && filled($email)) {
            $client = Client::query()->where('email', $email)->first();
        }

        if (! $client && filled($phone)) {
            $client = Client::query()->where('phone', $phone)->first();
        }

        $payload = array_filter([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'odoo_partner_id' => $odooPartnerId,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        if ($client) {
            $client->fill($payload)->save();

            return 'updated';
        }

        try {
            Client::query()->create([
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'odoo_partner_id' => $odooPartnerId,
            ]);

            return 'created';
        } catch (\Throwable $exception) {
            Log::warning('Odoo CRM client import skipped a row.', [
                'name' => $name,
                'email' => $email,
                'odoo_partner_id' => $odooPartnerId,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
