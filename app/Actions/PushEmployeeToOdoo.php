<?php

namespace App\Actions;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Services\OdooClient;
use Illuminate\Support\Facades\Log;

class PushEmployeeToOdoo
{
    private ?string $lastError = null;

    public function __construct(private OdooClient $odoo) {}

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function handle(Employee $employee): Employee
    {
        if (! $this->odoo->configured() || $employee->status !== EmployeeStatus::Approved) {
            return $employee;
        }

        $this->lastError = null;

        try {
            if (filled($employee->odoo_employee_id)) {
                $this->odoo->writeEmployee((string) $employee->odoo_employee_id, [
                    'name' => $employee->name,
                    'work_email' => $employee->email,
                    'work_phone' => $employee->phone,
                    'barcode' => $employee->code,
                    'active' => $employee->is_active,
                ]);

                return $this->reload($employee);
            }

            $employeeId = $this->odoo->createOrReuseEmployee(
                $employee->name,
                $employee->email,
                $employee->phone,
                $employee->code,
                $employee->is_active,
            );
            if (! is_numeric($employeeId) || (int) $employeeId <= 0) {
                $this->lastError = 'Odoo did not return an employee id.';

                return $this->reload($employee);
            }
            $employee->forceFill(['odoo_employee_id' => (string) $employeeId])->save();
        } catch (\Throwable $exception) {
            $this->lastError = $exception->getMessage();
            Log::warning('Odoo employee sync failed.', [
                'employee_id' => $employee->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return $this->reload($employee);
    }

    private function reload(Employee $employee): Employee
    {
        if (! $employee->exists) {
            return $employee;
        }

        return $employee->refresh();
    }
}
