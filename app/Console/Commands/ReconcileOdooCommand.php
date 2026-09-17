<?php

namespace App\Console\Commands;

use App\Actions\HydrateClientFromOdoo;
use App\Actions\HydrateEmployeeFromOdoo;
use App\Actions\ImportOdooCrmClients;
use App\Actions\PushEmployeeToOdoo;
use App\Actions\SyncOdooEmployees;
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

    protected $description = 'Pull Odoo CRM/HR changes into the dashboard and push unsynced local writes.';

    public function handle(
        OdooClient $odoo,
        ImportOdooCrmClients $importOdooCrmClients,
        SyncOdooEmployees $syncOdooEmployees,
        HydrateClientFromOdoo $hydrateClientFromOdoo,
        HydrateEmployeeFromOdoo $hydrateEmployeeFromOdoo,
        PushEmployeeToOdoo $pushEmployeeToOdoo,
    ): int {
        if (! $odoo->configured()) {
            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));

        try {
            $importOdooCrmClients->handle($limit);
        } catch (\Throwable $exception) {
            Log::warning('odoo:reconcile CRM pull failed.', [
                'error' => $exception->getMessage(),
            ]);
        }

        try {
            $syncOdooEmployees->handle($limit);
        } catch (\Throwable $exception) {
            Log::warning('odoo:reconcile HR pull failed.', [
                'error' => $exception->getMessage(),
            ]);
        }

        foreach ($this->nextClientBatch($limit) as $client) {
            try {
                $hydrateClientFromOdoo->handle($client);
            } catch (\Throwable $exception) {
                Log::warning('odoo:reconcile client failed.', [
                    'client_id' => $client->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $employees = Employee::query()
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($employees as $employee) {
            try {
                if (filled($employee->odoo_employee_id)) {
                    $hydrateEmployeeFromOdoo->handle($employee);
                } else {
                    $pushEmployeeToOdoo->handle($employee);
                }
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
