<?php

namespace App\Actions;

use App\Enums\EmployeeProfession;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;

class RequestStaffJoin
{
    public function __construct(
        private GenerateEmployeeCode $generateEmployeeCode,
        private NotifyEmployees $notifyEmployees,
    ) {}

    public function handle(string $telegramUserId, string $name, ?string $username = null): Employee
    {
        return DB::transaction(function () use ($telegramUserId, $name, $username) {
            $employee = Employee::query()->where('telegram_user_id', $telegramUserId)->first();

            if ($employee) {
                $employee->fill([
                    'name' => $name,
                    'telegram_username' => $username ?: $employee->telegram_username,
                ]);

                if ($employee->status === EmployeeStatus::Rejected) {
                    $employee->status = EmployeeStatus::Pending;
                    $employee->is_active = false;
                    $employee->save();
                    $this->notifyJoin($employee);

                    return $employee;
                }

                $employee->save();

                return $employee;
            }

            $employee = Employee::query()->create([
                'code' => $this->generateEmployeeCode->handle(),
                'name' => $name,
                'telegram_user_id' => $telegramUserId,
                'telegram_username' => $username,
                'profession' => EmployeeProfession::Sales,
                'status' => EmployeeStatus::Pending,
                'is_active' => false,
            ]);

            $this->notifyJoin($employee);

            return $employee;
        });
    }

    private function notifyJoin(Employee $employee): void
    {
        $this->notifyEmployees->handlePlain(
            EmployeeProfession::Sales,
            "طلب انضمام موظف جديد: {$employee->name}. راجعه من شاشة الموظفين.",
        );
    }
}
