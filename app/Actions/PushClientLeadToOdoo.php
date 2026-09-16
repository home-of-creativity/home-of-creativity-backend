<?php

namespace App\Actions;

use App\Models\Client;
use App\Services\GeminiService;
use App\Services\OdooClient;
use Illuminate\Support\Facades\Log;

class PushClientLeadToOdoo
{
    public function __construct(
        private OdooClient $odoo,
        private GeminiService $gemini,
    ) {}

    public function handle(Client $client): Client
    {
        if (! $client->profileComplete()) {
            return $client;
        }

        if (! $this->odoo->configured()) {
            return $client;
        }

        if (filled($client->odoo_lead_id)) {
            return $client;
        }

        try {
            $industry = $client->company_activity
                ?: $this->gemini->classifyCompanyIndustry((string) $client->company_name);
            if ($industry && $client->company_activity !== $industry) {
                $client->forceFill(['company_activity' => $industry])->save();
            }

            $tagIds = [];
            if (filled($industry)) {
                $tagId = $this->odoo->ensureCrmTagId($industry);
                if ($tagId) {
                    $tagIds[] = $tagId;
                }
            }

            $leadId = $this->odoo->createCrmLead([
                'name' => $client->company_name.' — '.$client->name,
                'contact_name' => $client->name,
                'partner_name' => $client->company_name,
                'phone' => $client->phone,
                'mobile' => $client->phone,
                'email_from' => $client->email,
                'description' => 'Lead from Telegram client bot'
                    .($industry ? "\nالنشاط: {$industry}" : ''),
                'tag_ids' => $tagIds !== [] ? $tagIds : null,
            ]);

            if ($leadId > 0) {
                $snapshot = $this->odoo->leadSnapshot($leadId);
                $client->forceFill([
                    'odoo_lead_id' => (string) $leadId,
                    'odoo_stage_name' => $snapshot['stage'] ?? 'تلغرام',
                ])->save();
            }
        } catch (\Throwable $exception) {
            Log::warning('Odoo CRM lead create failed for client.', [
                'client_id' => $client->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return $client->fresh() ?? $client;
    }
}
