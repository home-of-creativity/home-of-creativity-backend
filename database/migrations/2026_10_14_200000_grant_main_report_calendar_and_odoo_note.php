<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Seeing the main reports calendar became its own switch; roles that saw it keep it.
        // Roles that write Odoo papers may also leave internal notes on them.
        foreach (DB::table('roles')->get(['id', 'abilities']) as $role) {
            $abilities = json_decode((string) $role->abilities, true);
            if (! is_array($abilities)) {
                continue;
            }
            $next = $abilities;
            if (array_intersect(['ops.reports', 'ops.reports.view'], $abilities) !== [] && ! in_array('ops.report_calendar.main', $abilities, true)) {
                $next[] = 'ops.report_calendar.main';
            }
            if (in_array('ops.clients.odoo', $abilities, true) && ! in_array('ops.clients.odoo_note', $abilities, true)) {
                $next[] = 'ops.clients.odoo_note';
            }
            if ($next !== $abilities) {
                DB::table('roles')->where('id', $role->id)->update(['abilities' => json_encode(array_values($next))]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->get(['id', 'abilities']) as $role) {
            $abilities = json_decode((string) $role->abilities, true);
            if (! is_array($abilities)) {
                continue;
            }
            $next = array_values(array_diff($abilities, ['ops.report_calendar.main', 'ops.clients.odoo_note']));
            if ($next !== $abilities) {
                DB::table('roles')->where('id', $role->id)->update(['abilities' => json_encode($next)]);
            }
        }
    }
};
