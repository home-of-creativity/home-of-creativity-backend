<?php

use App\Enums\StaffAbility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Requests, clients, and employees gained named operations. Each role keeps what it
     * could do: old create and update grants become the operations those verbs covered.
     */
    public function up(): void
    {
        foreach (DB::table('roles')->get(['id', 'abilities']) as $role) {
            $abilities = json_decode((string) $role->abilities, true);
            if (! is_array($abilities)) {
                continue;
            }
            $next = StaffAbility::upgradeLegacy($abilities);
            if ($next !== $abilities) {
                DB::table('roles')->where('id', $role->id)->update(['abilities' => json_encode($next)]);
            }
        }
    }

    public function down(): void
    {
        // Operations are a superset of the old verbs; nothing to undo.
    }
};
