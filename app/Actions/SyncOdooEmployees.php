<?php

namespace App\Actions;

use App\Enums\EmployeeProfession;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Services\OdooClient;
use Illuminate\Support\Facades\Log;

class SyncOdooEmployees
{
    public function __construct(
        private OdooClient $odoo,
        private GenerateEmployeeCode $generateEmployeeCode,
        private PushEmployeeToOdoo $pushEmployeeToOdoo,
    ) {}

    /**
     * @return array{synced: int, created: int, updated: int, pushed: int}
     */
    public function handle(int $limit = 200): array
    {
        if (! $this->odoo->configured()) {
            return ['synced' => 0, 'created' => 0, 'updated' => 0, 'pushed' => 0];
        }

        $rows = $this->odoo->listEmployees($limit);
        $created = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $odooId = (string) $row['id'];
            $employee = Employee::query()->where('odoo_employee_id', $odooId)->first();

            if (! $employee && filled($row['email'])) {
                $employee = Employee::query()->where('email', $row['email'])->first();
            }

            if ($employee) {
                $employee->fill([
                    'name' => $row['name'] !== '' ? $row['name'] : $employee->name,
                    'email' => $row['email'] ?? $employee->email,
                    'phone' => $row['phone'] ?? $employee->phone,
                    'odoo_employee_id' => $odooId,
                    'is_active' => $row['active'],
                ])->save();
                $updated++;

                continue;
            }

            try {
                Employee::query()->create([
                    'code' => $this->uniqueCode($row['barcode'] ?? null),
                    'name' => $row['name'] !== '' ? $row['name'] : 'Odoo employee',
                    'email' => $row['email'],
                    'phone' => $row['phone'],
                    'odoo_employee_id' => $odooId,
                    'profession' => EmployeeProfession::Sales,
                    'status' => EmployeeStatus::Approved,
                    'is_active' => $row['active'],
                ]);
                $created++;
            } catch (\Throwable $exception) {
                Log::warning('Odoo employee sync skipped a row.', [
                    'odoo_employee_id' => $odooId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $pushed = 0;
        Employee::query()
            ->where('status', EmployeeStatus::Approved)
            ->whereNull('odoo_employee_id')
            ->each(function (Employee $employee) use (&$pushed): void {
                $this->pushEmployeeToOdoo->handle($employee);
                if (filled($employee->fresh()?->odoo_employee_id)) {
                    $pushed++;
                }
            });

        return [
            'synced' => count($rows),
            'created' => $created,
            'updated' => $updated,
            'pushed' => $pushed,
        ];
    }

    private function uniqueCode(?string $barcode): string
    {
        if (filled($barcode) && Employee::query()->where('code', $barcode)->doesntExist()) {
            return $barcode;
        }

        return $this->generateEmployeeCode->handle();
    }
}
