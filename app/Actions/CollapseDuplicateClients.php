<?php

namespace App\Actions;

use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CollapseDuplicateClients
{
    public function handle(): int
    {
        $removed = 0;

        DB::transaction(function () use (&$removed): void {
            $clients = Client::query()->withCount('requests')->orderBy('id')->get();
            $parent = [];
            foreach ($clients as $client) {
                $parent[$client->id] = $client->id;
            }

            $find = function (int $id) use (&$parent, &$find): int {
                if ($parent[$id] !== $id) {
                    $parent[$id] = $find($parent[$id]);
                }

                return $parent[$id];
            };
            $union = function (int $left, int $right) use (&$parent, $find): void {
                $leftRoot = $find($left);
                $rightRoot = $find($right);
                if ($leftRoot !== $rightRoot) {
                    $parent[$rightRoot] = $leftRoot;
                }
            };

            $seen = [];
            $claim = function (string $key, int $id) use (&$seen, $union): void {
                if (isset($seen[$key])) {
                    $union($seen[$key], $id);

                    return;
                }
                $seen[$key] = $id;
            };

            foreach ($clients as $client) {
                $lead = trim((string) $client->odoo_lead_id);
                if ($lead !== '') {
                    $claim('lead:'.$lead, $client->id);
                }
                $partner = trim((string) $client->odoo_partner_id);
                if ($partner !== '') {
                    $claim('partner:'.$partner, $client->id);
                }
                $email = strtolower(trim((string) $client->email));
                if (str_contains($email, '@')) {
                    $claim('email:'.$email, $client->id);
                }
                $phone = preg_replace('/\D+/', '', (string) $client->phone) ?? '';
                if (strlen($phone) >= 8) {
                    $claim('phone:'.$phone, $client->id);
                }
            }

            $groups = [];
            foreach ($clients as $client) {
                $groups[$find($client->id)][] = $client;
            }

            foreach ($groups as $group) {
                if (count($group) < 2) {
                    continue;
                }

                usort($group, function (Client $left, Client $right): int {
                    return [
                        $right->requests_count,
                        filled($right->telegram_user_id) ? 1 : 0,
                        $left->id,
                    ] <=> [
                        $left->requests_count,
                        filled($left->telegram_user_id) ? 1 : 0,
                        $right->id,
                    ];
                });

                /** @var Client $keeper */
                $keeper = $group[0];
                foreach (array_slice($group, 1) as $duplicate) {
                    $this->keepMissingIdentity($keeper, $duplicate);
                    $this->moveOwnedRows($duplicate->id, $keeper->id);
                    $duplicate->forceFill([
                        'odoo_lead_id' => null,
                        'odoo_partner_id' => null,
                        'telegram_user_id' => null,
                    ])->save();
                    $duplicate->delete();
                    $removed++;
                }
                $keeper->save();
            }
        });

        return $removed;
    }

    private function keepMissingIdentity(Client $keeper, Client $duplicate): void
    {
        $fill = [];
        foreach (['email', 'phone', 'company_name', 'company_activity', 'odoo_lead_id', 'odoo_partner_id', 'odoo_stage_name', 'telegram_user_id', 'google_drive_folder_id'] as $field) {
            if (! filled($keeper->{$field}) && filled($duplicate->{$field})) {
                $fill[$field] = $duplicate->{$field};
            }
        }
        if ($fill !== []) {
            $keeper->forceFill($fill);
        }
    }

    private function moveOwnedRows(int $fromId, int $toId): void
    {
        foreach (['requests', 'client_reports', 'support_messages', 'odoo_lead_notes'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'client_id')) {
                continue;
            }
            DB::table($table)->where('client_id', $fromId)->update(['client_id' => $toId]);
        }
    }
}
