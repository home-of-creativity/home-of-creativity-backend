<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_projects')) {
            return;
        }

        DB::table('portfolio_projects')->whereBetween('id', [1, 6])->delete();
    }

    public function down(): void
    {
        // Projects 1–6 were seeded collages. They are not restored.
    }
};
