<?php

namespace App\Console\Commands;

use App\Actions\PushClientLeadToOdoo;
use App\Actions\PushClientToOdoo;
use App\Actions\PushEmployeeToOdoo;
use App\Models\Client;
use App\Models\Employee;
use App\Services\OdooClient;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ReconcileOdooCommand extends Command
{
    protected $signature = 'odoo:reconcile {--limit=50}';

    protected $description = 'Safety-net pull of Odoo lead/partner snapshots and push unsynced local writes.';

    public function handle(
        OdooClient $odoo,
        PushClientToOdoo $pushClientToOdoo,
        PushClientLeadToOdoo $pushClientLeadToOdoo,
        PushEmployeeToOdoo $pushEmployeeToOdoo,
    ): int {
        if (! $odoo->configured()) {
            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $clients = $this->nextClientBatch($limit);

        foreach ($clients as $client) {
            try {
                $pushClientToOdoo->handle($client);
                $pushClientLeadToOdoo->handle($client->fresh() ?? $client);
                $fresh = $client->fresh() ?? $client;
                if (filled($fresh->odoo_lead_id)) {
                    $snapshot = $odoo->leadSnapshot((int) $fresh->odoo_lead_id);
                    if ($snapshot && ($snapshot['stage'] ?? null) !== $fresh->odoo_stage_name) {
                        $fresh->forceFill(['odoo_stage_name' => $snapshot['stage']])->save();
                    }
                }
            } catch (\Throwable $exception) {
                Log::warning('odoo:reconcile client failed.', [
                    'client_id' => $client->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $employees = Employee::query()
            ->whereNull('odoo_employee_id')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($employees as $employee) {
            try {
                $pushEmployeeToOdoo->handle($employee);
            } catch (\Throwable $exception) {
                Log::warning('odoo:reconcile employee failed.', [
                    'employee_id' => $employee->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Client>
     */
    private function nextClientBatch(int $limit)
    {
        $lastId = (int) Cache::get('odoo:reconcile:last_client_id', 0);
        $clients = Client::query()
            ->where('id', '>', $lastId)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($clients->isEmpty() && $lastId > 0) {
            Cache::forget('odoo:reconcile:last_client_id');
            $clients = Client::query()->orderBy('id')->limit($limit)->get();
        }

        if ($clients->isNotEmpty()) {
            Cache::put('odoo:reconcile:last_client_id', (int) $clients->last()->id, now()->addDay());
        }

        return $clients;
    }
}
