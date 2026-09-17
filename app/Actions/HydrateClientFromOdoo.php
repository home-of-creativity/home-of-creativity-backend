<?php

namespace App\Actions;

use App\Models\Client;
use App\Services\OdooClient;
use App\Support\ClientProfileValue;
use Illuminate\Support\Facades\Log;

class HydrateClientFromOdoo
{
    public function __construct(private OdooClient $odoo) {}

    public function handle(Client $client): Client
    {
        if (! $this->odoo->configured()) {
            return $client;
        }

        $hadOdoo = filled($client->odoo_lead_id) || filled($client->odoo_partner_id);
        $live = null;

        if (filled($client->odoo_lead_id)) {
            $live = $this->odoo->leadSnapshot((int) $client->odoo_lead_id);
            if ($live === null) {
                $client->forceFill([
                    'odoo_lead_id' => null,
                    'odoo_stage_name' => null,
                ])->save();
            } else {
                $name = ClientProfileValue::usableName($live['contact_name'] ?? null)
                    ?? ClientProfileValue::usableName($client->name)
                    ?? $client->name;
                $company = filled($live['partner_name'] ?? null) ? (string) $live['partner_name'] : $client->company_name;
                if (ClientProfileValue::looksLikePhone($company)) {
                    $company = ClientProfileValue::usableName($client->company_name) ?? $client->company_name;
                }
                $phone = ClientProfileValue::usablePhone($live['phone'] ?? null)
                    ?? ClientProfileValue::usablePhone($client->phone);

                $client->forceFill(array_filter([
                    'name' => $name,
                    'company_name' => $company,
                    'email' => $live['email'] ?? null,
                    'phone' => $phone,
                    'odoo_stage_name' => $live['stage'] ?? null,
                    'odoo_partner_id' => $live['partner_id'] ?? $client->odoo_partner_id,
                ], fn (mixed $value): bool => $value !== null && $value !== ''))->save();
                $client->setAttribute('odoo_live', $live);
            }
        }

        if (filled($client->odoo_partner_id)) {
            $partner = $this->odoo->partnerSnapshot((int) $client->odoo_partner_id);
            if ($partner === null) {
                if ($live === null) {
                    $client->forceFill(['odoo_partner_id' => null])->save();
                }
            } elseif ($live === null) {
                $updates = [];
                if (! ClientProfileValue::usableName($client->name)) {
                    $updates['name'] = ClientProfileValue::usableName($partner['name'] ?? null);
                }
                if (! filled($client->email) && filled($partner['email'] ?? null)) {
                    $updates['email'] = $partner['email'];
                }
                if (! ClientProfileValue::usablePhone($client->phone)) {
                    $updates['phone'] = ClientProfileValue::usablePhone($partner['phone'] ?? null);
                }
                $client->forceFill(array_filter($updates, fn (mixed $value): bool => $value !== null && $value !== ''))->save();
            }
        }

        $missing = ! filled($client->odoo_lead_id) && ! filled($client->odoo_partner_id);
        if ($hadOdoo && $missing && ! filled($client->telegram_user_id) && $client->requests()->doesntExist()) {
            try {
                $client->delete();
            } catch (\Throwable $exception) {
                Log::warning('Could not remove local client after Odoo delete.', [
                    'client_id' => $client->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $liveAttr = $client->getAttribute('odoo_live');
        if (! $client->exists) {
            return $client;
        }

        $fresh = $client->fresh() ?? $client;
        if (is_array($liveAttr)) {
            $fresh->setAttribute('odoo_live', $liveAttr);
        }

        return $fresh;
    }
}
