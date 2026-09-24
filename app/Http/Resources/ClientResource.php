<?php

namespace App\Http\Resources;

use App\Models\Client;
use App\Services\OdooClient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Client */
class ClientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $odoo = app(OdooClient::class);
        $odooReady = $odoo->configured();
        $live = $this->odoo_live ?? null;
        if (! is_array($live) && filled($this->odoo_stage_name)) {
            $live = [
                'stage' => $this->odoo_stage_name,
                'name' => $this->name,
            ];
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'company_name' => $this->company_name,
            'company_activity' => $this->company_activity,
            'email' => $this->email,
            'phone' => $this->phone,
            'locale' => $this->locale,
            'telegram_user_id' => $this->telegram_user_id,
            'telegram_url' => $this->telegramPrivateUrl(),
            'channel' => $this->isWhatsApp() ? 'whatsapp' : (filled($this->telegram_user_id) ? 'telegram' : null),
            'odoo_partner_id' => $this->odoo_partner_id,
            'odoo_lead_id' => $this->odoo_lead_id,
            'odoo_stage_name' => $this->odoo_stage_name,
            'odoo_url' => filled($this->odoo_partner_id) && $odooReady
                ? $odoo->recordUrl('res.partner', (string) $this->odoo_partner_id)
                : (filled($this->odoo_lead_id) && $odooReady
                    ? $odoo->recordUrl('crm.lead', (string) $this->odoo_lead_id)
                    : null),
            'odoo_lead_url' => filled($this->odoo_lead_id) && $odooReady
                ? $odoo->recordUrl('crm.lead', (string) $this->odoo_lead_id)
                : null,
            'odoo_live' => is_array($live) ? $live : null,
            'requests_count' => $this->whenCounted('requests'),
            'reports_count' => $this->whenCounted('reports'),
            'google_drive_folder_id' => $this->google_drive_folder_id,
            'google_drive_folder_url' => $this->googleDriveFolderUrl(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
