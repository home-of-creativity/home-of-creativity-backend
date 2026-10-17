<?php

use App\Enums\StaffAbility;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Reports page tabs (list, calendar, edit history) each get their own
 * operations. Roles that already had report CRUD keep the matching tab ops.
 */
return new class extends Migration
{
    public function up(): void
    {
        Role::query()->each(function (Role $role): void {
            $current = array_values(array_filter($role->abilities ?? [], is_string(...)));
            if ($current === []) {
                return;
            }

            $granted = StaffAbility::expand($current);
            $holds = static function (string $ability) use ($granted, $current): bool {
                if (in_array($ability, $granted, true) || in_array($ability, $current, true)) {
                    return true;
                }
                $dot = strrpos($ability, '.');
                if ($dot === false) {
                    return false;
                }

                return in_array(substr($ability, 0, $dot), $current, true);
            };

            $next = $granted;

            if ($holds('ops.reports.view')) {
                $next[] = 'ops.reports.calendar_view';
                $next[] = 'ops.reports.history_view';
            }
            if ($holds('ops.reports.create')) {
                $next[] = 'ops.reports.calendar_create';
            }
            if ($holds('ops.reports.update')) {
                $next[] = 'ops.reports.calendar_update';
                $next[] = 'ops.reports.history_restore';
            }
            if ($holds('ops.reports.delete')) {
                $next[] = 'ops.reports.calendar_delete';
            }

            $role->forceFill([
                'abilities' => array_values(array_unique(array_filter(
                    $next,
                    fn (string $ability): bool => $ability !== 'ops.reports',
                ))),
            ])->save();
        });
    }

    public function down(): void
    {
        //
    }
};
