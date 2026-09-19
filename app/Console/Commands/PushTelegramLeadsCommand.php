<?php

namespace App\Console\Commands;

use App\Actions\PushClientLeadToOdoo;
use App\Actions\PushClientToOdoo;
use App\Models\Client;
use App\Services\OdooClient;
use Illuminate\Console\Command;

class PushTelegramLeadsCommand extends Command
{
    protected $signature = 'odoo:push-telegram';

    protected $description = 'Create missing CRM opportunities on the existing Telegram pipeline.';

    public function handle(
        OdooClient $odoo,
        PushClientToOdoo $pushClientToOdoo,
        PushClientLeadToOdoo $pushClientLeadToOdoo,
    ): int {
        if (! $odoo->configured()) {
            $this->error('Odoo is not configured.');

            return self::FAILURE;
        }

        $pipeline = $odoo->resolveTelegramPipeline();
        $this->info('Telegram pipeline stage='.($pipeline['stage_id'] ?? 'none').' team='.($pipeline['team_id'] ?? 'none').' user='.($odoo->crmOwnerUserId() ?? 'none'));

        $clients = Client::query()->orderBy('id')->get();
        $missing = $clients->where(fn (Client $client): bool => blank($client->odoo_lead_id))->count();
        $this->info('Clients missing a lead: '.$missing);

        $pushed = 0;
        foreach ($clients as $client) {
            if (! $client->readyForOdoo()) {
                $this->line("skip #{$client->id} incomplete profile");

                continue;
            }

            $hadLead = filled($client->odoo_lead_id);
            $client = $pushClientLeadToOdoo->handle($pushClientToOdoo->handle($client), $hadLead, false);
            if (filled($client->odoo_lead_id)) {
                $this->info(($hadLead ? 'updated' : 'pushed')." #{$client->id} -> lead {$client->odoo_lead_id}");
                $pushed++;

                continue;
            }

            $error = $client->getAttribute('odoo_push_error') ?: 'no lead id';
            $this->error("failed #{$client->id}: {$error}");
        }

        $this->info("Pushed {$pushed} leads.");

        return $pushed > 0 || $clients->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}
