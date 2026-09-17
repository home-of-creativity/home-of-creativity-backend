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
        private PushClientToOdoo $pushClientToOdoo,
        private PushClientLeadToOdoo $pushClientLeadToOdoo,
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
        $pushedLeads = $this->pushMissingLeads();

        return [
            'imported' => $fromCrm['created'] + $fromCrm['updated'] + $fromPartners['created'] + $fromPartners['updated'],
            'created' => $fromCrm['created'] + $fromPartners['created'],
            'updated' => $fromCrm['updated'] + $fromPartners['updated'],
            'pushed' => $fromPartners['pushed'] + $pushedLeads,
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
                companyName: $lead['company_name'] ?? null,
                odooPartnerId: $lead['odoo_partner_id'],
                odooLeadId: (string) $lead['lead_id'],
                odooStageName: $lead['odoo_stage_name'] ?? null,
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

    private function upsertClient(
        string $name,
        ?string $email,
        ?string $phone,
        ?string $companyName,
        ?string $odooPartnerId,
        ?string $odooLeadId,
        ?string $odooStageName = null,
    ): ?string {
        $client = null;

        if (filled($odooLeadId)) {
            $client = Client::query()->where('odoo_lead_id', $odooLeadId)->first();
        }

        if (! $client && filled($odooPartnerId)) {
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
            'company_name' => $companyName,
            'odoo_partner_id' => $odooPartnerId,
            'odoo_lead_id' => $odooLeadId,
            'odoo_stage_name' => $odooStageName,
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
                'company_name' => $companyName,
                'odoo_partner_id' => $odooPartnerId,
                'odoo_lead_id' => $odooLeadId,
                'odoo_stage_name' => $odooStageName,
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

    private function pushMissingLeads(): int
    {
        $pushed = 0;

        Client::query()
            ->whereNull('odoo_lead_id')
            ->orderBy('id')
            ->each(function (Client $client) use (&$pushed): void {
                $client = $this->pushClientToOdoo->handle($client);
                $client = $this->pushClientLeadToOdoo->handle($client, false, false);
                if (filled($client->odoo_lead_id)) {
                    $pushed++;
                }
            });

        return $pushed;
    }
}
