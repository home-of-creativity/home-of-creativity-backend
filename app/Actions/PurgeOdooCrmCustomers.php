<?php

namespace App\Actions;

use App\Models\Client;
use App\Services\OdooClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PurgeOdooCrmCustomers
{
    public function __construct(private OdooClient $odoo) {}

    /**
     * @return array{leads: int, partners: int, local_clients: int}
     */
    public function handle(): array
    {
        if (! $this->odoo->configured()) {
            throw new RuntimeException('Odoo is not configured.');
        }

        $this->odoo->ensureCrmStage('تلغرام');
        $purged = $this->odoo->purgeCrmCustomers();

        $localClients = 0;
        try {
            $localClients = Client::query()
                ->where(function ($query): void {
                    $query->whereNotNull('odoo_lead_id')
                        ->orWhereNotNull('odoo_partner_id')
                        ->orWhereNotNull('odoo_stage_name');
                })
                ->update([
                    'odoo_lead_id' => null,
                    'odoo_partner_id' => null,
                    'odoo_stage_name' => null,
                ]);
        } catch (Throwable $exception) {
            Log::warning('Cleared Odoo CRM records, but local client ids could not be reset.', [
                'error' => $exception->getMessage(),
            ]);
        }

        return [
            'leads' => $purged['leads'],
            'partners' => $purged['partners'],
            'local_clients' => $localClients,
        ];
    }
}
