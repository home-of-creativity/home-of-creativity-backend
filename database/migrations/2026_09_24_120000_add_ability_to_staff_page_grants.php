<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_page_grants', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'page_key']);
        });

        Schema::table('staff_page_grants', function (Blueprint $table) {
            $table->string('ability')->nullable()->after('user_id');
        });

        $pageAbilities = ['social.content', 'social.approve', 'social.engage', 'social.messages'];

        foreach (DB::table('staff_page_grants')->orderBy('id')->get() as $row) {
            $user = DB::table('users')->where('id', $row->user_id)->first();
            $stored = [];
            if ($user?->role_id) {
                $role = DB::table('roles')->where('id', $user->role_id)->first();
                $decoded = json_decode($role->abilities ?? '[]', true);
                $stored = is_array($decoded) ? $decoded : [];
            }
            $abilities = array_values(array_intersect($stored, $pageAbilities));
            if ($abilities === []) {
                $abilities = ['social.content'];
            }

            DB::table('staff_page_grants')->where('id', $row->id)->update([
                'ability' => $abilities[0],
            ]);

            foreach (array_slice($abilities, 1) as $ability) {
                DB::table('staff_page_grants')->insert([
                    'user_id' => $row->user_id,
                    'ability' => $ability,
                    'page_key' => $row->page_key,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::table('staff_page_grants', function (Blueprint $table) {
            $table->unique(['user_id', 'ability', 'page_key']);
        });
    }

    public function down(): void
    {
        Schema::table('staff_page_grants', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'ability', 'page_key']);
            $table->dropColumn('ability');
            $table->unique(['user_id', 'page_key']);
        });
    }
};
