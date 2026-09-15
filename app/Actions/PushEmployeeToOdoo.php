<?php

namespace App\Actions;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Services\OdooClient;
use Illuminate\Support\Facades\Log;

class PushEmployeeToOdoo
{
    public function __construct(private OdooClient $odoo) {}

    public function handle(Employee $employee): Employee
    {
        if (! $this->odoo->configured() || $employee->status !== EmployeeStatus::Approved) {
            return $employee;
        }

        try {
            if (filled($employee->odoo_employee_id)) {
                $this->odoo->writeEmployee((string) $employee->odoo_employee_id, [
                    'name' => $employee->name,
                    'work_email' => $employee->email,
                    'work_phone' => $employee->phone,
                    'barcode' => $employee->code,
                    'active' => $employee->is_active,
                ]);

                return $employee;
            }

            $employeeId = $this->odoo->createOrReuseEmployee(
                $employee->name,
                $employee->email,
                $employee->phone,
                $employee->code,
                $employee->is_active,
            );
            $employee->forceFill(['odoo_employee_id' => $employeeId])->save();
        } catch (\Throwable $exception) {
            Log::warning('Odoo employee sync failed.', [
                'employee_id' => $employee->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return $employee->fresh() ?? $employee;
    }
}
