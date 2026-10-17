<?php

use App\Enums\StaffAbility;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Client page tabs (list, logos, quotations, invoices) each get their own
 * operations. Roles that already had Odoo paper access or client/site CRUD keep
 * the matching tab operations so nothing disappears on deploy.
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

            if ($holds('ops.clients.odoo')) {
                $next = array_merge($next, [
                    'ops.clients.quotations_view',
                    'ops.clients.quotations_create',
                    'ops.clients.quotations_update',
                    'ops.clients.invoices_view',
                    'ops.clients.invoices_create',
                    'ops.clients.invoices_update',
                ]);
            }

            if ($holds('ops.clients.view') || $holds('site.projects.view')) {
                $next[] = 'ops.clients.logos_view';
            }
            if ($holds('ops.clients.create') || $holds('site.projects.create')) {
                $next[] = 'ops.clients.logos_create';
            }
            if ($holds('ops.clients.update') || $holds('site.projects.update')) {
                $next[] = 'ops.clients.logos_update';
            }
            if ($holds('ops.clients.delete') || $holds('site.projects.delete')) {
                $next[] = 'ops.clients.logos_delete';
            }

            $role->forceFill([
                'abilities' => array_values(array_unique(array_filter(
                    $next,
                    fn (string $ability): bool => ! in_array($ability, ['ops.clients', 'site.projects'], true),
                ))),
            ])->save();
        });
    }

    public function down(): void
    {
        //
    }
};
