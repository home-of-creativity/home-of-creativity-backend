<?php

namespace App\Actions;

use App\Models\Employee;
use App\Services\OdooClient;
use Illuminate\Support\Facades\Log;

class DeleteEmployee
{
    public function __construct(private OdooClient $odoo) {}

    public function handle(Employee $employee): void
    {
        if ($this->odoo->configured() && filled($employee->odoo_employee_id)) {
            try {
                $this->odoo->archiveOrUnlink('hr.employee', (string) $employee->odoo_employee_id);
            } catch (\Throwable $exception) {
                Log::warning('Odoo HR delete failed for employee.', [
                    'employee_id' => $employee->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $employee->delete();
    }
}
