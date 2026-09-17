<?php

namespace App\Actions;

use App\Models\Client;
use App\Services\OdooClient;
use Illuminate\Support\Facades\Log;

class DeleteClient
{
    public function __construct(private OdooClient $odoo) {}

    public function handle(Client $client): void
    {
        if ($this->odoo->configured()) {
            try {
                if (filled($client->odoo_lead_id)) {
                    $this->odoo->archiveOrUnlink('crm.lead', (string) $client->odoo_lead_id);
                }
                if (filled($client->odoo_partner_id)) {
                    $this->odoo->archiveOrUnlink('res.partner', (string) $client->odoo_partner_id);
                }
            } catch (\Throwable $exception) {
                Log::warning('Odoo CRM delete failed for client.', [
                    'client_id' => $client->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $client->delete();
    }
}
