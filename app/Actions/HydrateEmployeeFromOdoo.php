<?php

namespace App\Actions;

use App\Models\Employee;
use App\Services\OdooClient;
use Illuminate\Support\Facades\Log;

class HydrateEmployeeFromOdoo
{
    public function __construct(private OdooClient $odoo) {}

    public function handle(Employee $employee): Employee
    {
        if (! $this->odoo->configured() || ! filled($employee->odoo_employee_id)) {
            return $employee;
        }

        $live = $this->odoo->employeeSnapshot((string) $employee->odoo_employee_id);
        if ($live === null) {
            $employee->forceFill(['odoo_employee_id' => null])->save();

            if (! filled($employee->telegram_user_id)) {
                try {
                    $employee->delete();
                } catch (\Throwable $exception) {
                    Log::warning('Could not remove local employee after Odoo delete.', [
                        'employee_id' => $employee->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            return $employee;
        }

        $employee->forceFill([
            ...array_filter([
                'name' => $live['name'] ?? null,
                'email' => $live['email'] ?? null,
                'phone' => $live['phone'] ?? null,
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
            'is_active' => (bool) ($live['active'] ?? true),
        ])->save();
        $employee->setAttribute('odoo_live', $live);

        if (! $employee->exists) {
            return $employee;
        }

        $fresh = $employee->fresh() ?? $employee;
        $fresh->setAttribute('odoo_live', $live);

        return $fresh;
    }
}
