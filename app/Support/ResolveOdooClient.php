<?php

namespace App\Support;

use App\Models\Client;
use App\Services\OdooClient;

/**
 * The dashboard client behind an Odoo partner. A paper is often made out to a contact
 * of the company, so the commercial partner and the parent are tried after the partner.
 */
class ResolveOdooClient
{
    public function __construct(private readonly OdooClient $odoo) {}

    public function forPartner(mixed $partnerId, mixed ...$fallbacks): ?Client
    {
        $ids = [];
        foreach ([$partnerId, ...$fallbacks] as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return null;
        }

        $found = $this->first($ids);
        if ($found !== null) {
            return $found;
        }

        $parents = array_values(array_diff($this->odoo->partnerParents($ids[0]), $ids));

        return $parents === [] ? null : $this->first($parents);
    }

    /** Whether the paper's partner is this client or one of its contacts. */
    public function belongsTo(Client $client, mixed $partnerId): bool
    {
        if (! filled($client->odoo_partner_id) || ! is_numeric($partnerId)) {
            return false;
        }
        if ((string) (int) $partnerId === (string) $client->odoo_partner_id) {
            return true;
        }

        return in_array((int) $client->odoo_partner_id, $this->odoo->partnerParents((int) $partnerId), true);
    }

    /** @param  list<int>  $ids */
    private function first(array $ids): ?Client
    {
        $clients = Client::query()
            ->whereIn('odoo_partner_id', array_map('strval', $ids))
            ->latest('id')
            ->get()
            ->keyBy(fn (Client $client): string => (string) $client->odoo_partner_id);
        foreach ($ids as $id) {
            if ($clients->has((string) $id)) {
                return $clients->get((string) $id);
            }
        }

        return null;
    }
}
