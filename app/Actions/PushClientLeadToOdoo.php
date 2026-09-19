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

    public function handle(Client $client, bool $writeExisting = false, bool $classifyIndustry = true): Client
    {
        if (! $client->readyForOdoo()) {
            return $client;
        }

        if (! $this->odoo->configured()) {
            $client->setAttribute('odoo_push_error', 'Odoo is not configured.');

            return $client;
        }

        if (filled($client->odoo_lead_id)) {
            if ($writeExisting) {
                $this->writeLead($client);
            }

            return $client->fresh() ?? $client;
        }

        if (! filled($client->name)) {
            return $client;
        }

        if (! filled($client->phone) && ! filled($client->email) && ! filled($client->company_name)) {
            return $client;
        }

        try {
            $industry = $client->company_activity;
            if ($classifyIndustry && ! filled($industry) && filled($client->company_name)) {
                try {
                    $industry = $this->gemini->classifyCompanyIndustry((string) $client->company_name);
                } catch (\Throwable) {
                    $industry = $client->company_activity;
                }
            }
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

            $leadId = $this->odoo->findCrmLead($this->leadName($client), $client->company_name)
                ?? $this->odoo->findCrmLeadByContact($client->phone, $client->email, $client->name);

            if ($leadId) {
                $client->forceFill([
                    'odoo_lead_id' => (string) $leadId,
                    'odoo_stage_name' => $client->odoo_stage_name ?: 'تلغرام',
                ])->save();
                $this->writeLead($client);

                return $client->fresh() ?? $client;
            }

            $leadId = $this->odoo->createCrmLead([
                'name' => $this->leadName($client),
                'contact_name' => $client->name,
                'partner_name' => $client->company_name,
                'phone' => $client->phone,
                'mobile' => $client->phone,
                'email_from' => $client->email,
                'partner_id' => filled($client->odoo_partner_id) ? (int) $client->odoo_partner_id : null,
                'description' => 'Lead from Home of Creativity'
                    .($industry ? "\nالنشاط: {$industry}" : ''),
                'tag_ids' => $tagIds !== [] ? $tagIds : null,
            ]);

            if ($leadId > 0) {
                $snapshot = $this->odoo->leadSnapshot($leadId) ?? [];
                $client->forceFill([
                    'odoo_lead_id' => (string) $leadId,
                    'odoo_stage_name' => filled($snapshot['stage'] ?? null) ? (string) $snapshot['stage'] : 'تلغرام',
                ])->save();
            }
        } catch (\Throwable $exception) {
            Log::error('Odoo CRM lead create failed for client.', [
                'client_id' => $client->id,
                'error' => $exception->getMessage(),
            ]);
            $client->setAttribute('odoo_push_error', $exception->getMessage());
        }

        return $client->fresh() ?? $client;
    }

    /**
     * Push contact details only. Pipeline stage, sales team, and salesperson
     * belong to the Odoo/sales side once a lead exists — a dashboard or bot
     * profile edit must never drag an opportunity that progressed past
     * تلغرام (e.g. تم الفوز بها) back to the initial stage.
     */
    private function writeLead(Client $client): void
    {
        try {
            $values = array_filter([
                'name' => $this->leadName($client),
                'contact_name' => $client->name,
                'partner_name' => $client->company_name,
                'phone' => $client->phone,
                'mobile' => $client->phone,
                'email_from' => $client->email,
                'partner_id' => filled($client->odoo_partner_id) ? (int) $client->odoo_partner_id : null,
            ], fn (mixed $value): bool => $value !== null && $value !== '');

            $telegramTagId = $this->odoo->ensureCrmTagId('تلغرام');
            if ($telegramTagId) {
                // Add-only command: keeps the تلغرام classification always
                // present without removing tags a staff member added in Odoo.
                $values['tag_ids'] = [[4, $telegramTagId]];
            }

            $this->odoo->writeRecord('crm.lead', (string) $client->odoo_lead_id, $values);
        } catch (\Throwable $exception) {
            Log::error('Odoo CRM lead update failed for client.', [
                'client_id' => $client->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function leadName(Client $client): string
    {
        $company = trim((string) $client->company_name);

        return $company !== '' ? $company.' — '.$client->name : $client->name;
    }
}
