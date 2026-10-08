<?php

use App\Enums\StaffAbility;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Role::query()->orderBy('id')->each(function (Role $role): void {
            $current = $role->abilities ?? [];
            $next = StaffAbility::inheritFeatureAbilities($current);
            if ($next !== array_values($current)) {
                $role->forceFill(['abilities' => $next])->save();
            }
        });
    }

    public function down(): void
    {
        $added = [
            StaffAbility::OpsFinance->value,
            StaffAbility::OpsReportGemini->value,
            StaffAbility::OpsReportTemplates->value,
            StaffAbility::OpsReportMedia->value,
            StaffAbility::OpsDrive->value,
        ];

        Role::query()->orderBy('id')->each(function (Role $role) use ($added): void {
            $current = $role->abilities ?? [];
            $next = array_values(array_filter(
                $current,
                fn (mixed $ability): bool => is_string($ability) && ! in_array($ability, $added, true),
            ));
            if ($next !== array_values($current)) {
                $role->forceFill(['abilities' => $next])->save();
            }
        });
    }
};
