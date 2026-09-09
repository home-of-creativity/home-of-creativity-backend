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
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'locale' => $this->locale,
            'telegram_user_id' => $this->telegram_user_id,
            'odoo_partner_id' => $this->odoo_partner_id,
            'odoo_url' => filled($this->odoo_partner_id) && app(OdooClient::class)->configured()
                ? app(OdooClient::class)->recordUrl('res.partner', (string) $this->odoo_partner_id)
                : null,
            'requests_count' => $this->whenCounted('requests'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
