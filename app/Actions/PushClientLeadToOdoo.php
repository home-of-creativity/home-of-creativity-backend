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
        }

        return $client->fresh() ?? $client;
    }

    private function writeLead(Client $client): void
    {
        try {
            $pipeline = $this->odoo->resolveTelegramPipeline();
            $this->odoo->writeRecord('crm.lead', (string) $client->odoo_lead_id, array_filter([
                'name' => $this->leadName($client),
                'contact_name' => $client->name,
                'partner_name' => $client->company_name,
                'phone' => $client->phone,
                'mobile' => $client->phone,
                'email_from' => $client->email,
                'partner_id' => filled($client->odoo_partner_id) ? (int) $client->odoo_partner_id : null,
                'stage_id' => $pipeline['stage_id'],
                'team_id' => $pipeline['team_id'],
            ], fn (mixed $value): bool => $value !== null && $value !== ''));

            if (blank($client->odoo_stage_name)) {
                $client->forceFill(['odoo_stage_name' => 'تلغرام'])->save();
            }
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
