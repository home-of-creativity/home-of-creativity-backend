<?php

namespace App\Actions;

use App\Models\Client;
use App\Services\OdooClient;

class SyncClientExpectedRevenue
{
    public function __construct(private OdooClient $odoo) {}

    public function handle(Client $client, bool $markWon = false): void
    {
        $leadId = (int) ($client->odoo_lead_id ?? 0);
        if ($leadId <= 0 || ! $this->odoo->configured()) {
            return;
        }

        $client->unsetRelation('requests');
        $won = $client->pipelineRevenue(wonOnly: true);
        $expected = $client->pipelineRevenue(wonOnly: false);

        if ($markWon) {
            $this->odoo->markLeadWon($leadId, $won > 0.009 ? $won : $expected);

            return;
        }

        $amount = $won > 0.009 ? $won : $expected;
        if ($amount > 0.009) {
            $this->odoo->writeLeadExpectedRevenue($leadId, $amount);
        }
    }
}
