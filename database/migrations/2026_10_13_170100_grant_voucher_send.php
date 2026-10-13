<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Roles that edit vouchers may also send them; a role with the whole feature already can. */
    public function up(): void
    {
        foreach (DB::table('roles')->get(['id', 'abilities']) as $role) {
            $abilities = json_decode((string) $role->abilities, true);
            if (! is_array($abilities) || ! in_array('ops.vouchers.update', $abilities, true) || in_array('ops.vouchers.send', $abilities, true)) {
                continue;
            }
            $abilities[] = 'ops.vouchers.send';
            DB::table('roles')->where('id', $role->id)->update(['abilities' => json_encode(array_values($abilities))]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->get(['id', 'abilities']) as $role) {
            $abilities = json_decode((string) $role->abilities, true);
            if (is_array($abilities) && in_array('ops.vouchers.send', $abilities, true)) {
                DB::table('roles')->where('id', $role->id)->update(['abilities' => json_encode(array_values(array_diff($abilities, ['ops.vouchers.send'])))]);
            }
        }
    }
};
