<?php

namespace App\Actions;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;

class GenerateEmployeeCode
{
    public function handle(): string
    {
        return DB::transaction(function () {
            $codes = Employee::query()
                ->where('code', 'like', 'EMP-%')
                ->lockForUpdate()
                ->pluck('code');

            $sequence = 0;
            foreach ($codes as $code) {
                if (is_string($code) && preg_match('/EMP-(\d+)$/', $code, $matches) === 1) {
                    $sequence = max($sequence, (int) $matches[1]);
                }
            }

            return sprintf('EMP-%04d', $sequence + 1);
        });
    }
}
