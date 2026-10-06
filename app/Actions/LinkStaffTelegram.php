<?php

namespace App\Actions;

use App\Models\Employee;
use Illuminate\Validation\ValidationException;

class LinkStaffTelegram
{
    public function handle(string $telegramUserId, string $code, ?string $username = null): Employee
    {
        $code = $this->normalizeCode($code);
        $employee = Employee::query()->where('code', $code)->first();

        if (! $employee) {
            throw ValidationException::withMessages([
                'code' => ['لا يوجد موظف بهذا الرقم. أضفه من اللوحة أو من أودو أولاً.'],
            ]);
        }

        $taken = Employee::query()
            ->where('telegram_user_id', $telegramUserId)
            ->whereKeyNot($employee->id)
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'code' => ['حساب تيليجرام هذا مربوط بموظف آخر.'],
            ]);
        }

        if (filled($employee->telegram_user_id) && $employee->telegram_user_id !== $telegramUserId) {
            throw ValidationException::withMessages([
                'code' => ['هذا الرقم مربوط بحساب تيليجرام آخر.'],
            ]);
        }

        $employee->forceFill([
            'telegram_user_id' => $telegramUserId,
            'telegram_username' => filled($username) ? $username : $employee->telegram_username,
        ])->save();

        return $employee->refresh();
    }

    private function normalizeCode(string $code): string
    {
        $code = strtoupper(trim($code));
        if (preg_match('/^\d+$/', $code) === 1) {
            return sprintf('EMP-%04d', (int) $code);
        }

        return $code;
    }
}
