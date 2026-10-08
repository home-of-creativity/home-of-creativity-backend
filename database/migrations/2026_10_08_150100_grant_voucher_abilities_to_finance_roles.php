<?php

use App\Enums\StaffAbility;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $added = [];
        foreach (StaffAbility::crudActions() as $action) {
            $added[] = StaffAbility::OpsVouchers->value.'.'.$action;
        }

        Role::query()->orderBy('id')->each(function (Role $role) use ($added): void {
            $current = array_values(array_filter($role->abilities ?? [], is_string(...)));
            if (! in_array(StaffAbility::OpsFinance->value, $current, true)) {
                return;
            }

            $next = array_values(array_unique([...$current, ...$added]));
            if ($next !== $current) {
                $role->forceFill(['abilities' => $next])->save();
            }
        });
    }

    public function down(): void
    {
        $added = [];
        foreach (StaffAbility::crudActions() as $action) {
            $added[] = StaffAbility::OpsVouchers->value.'.'.$action;
        }

        Role::query()->orderBy('id')->each(function (Role $role) use ($added): void {
            $current = array_values(array_filter($role->abilities ?? [], is_string(...)));
            $next = array_values(array_filter(
                $current,
                fn (string $ability): bool => ! in_array($ability, $added, true),
            ));
            if ($next !== $current) {
                $role->forceFill(['abilities' => $next])->save();
            }
        });
    }
};
