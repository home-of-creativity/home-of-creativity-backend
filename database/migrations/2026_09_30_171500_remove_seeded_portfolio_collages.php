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

        $ids = DB::table('portfolio_projects')
            ->where('image_path', 'like', '%googleusercontent.com%')
            ->orWhere('image_path', 'like', '%drive.google.com%')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('portfolio_projects')->whereIn('id', $ids)->delete();
    }

    public function down(): void
    {
        // The collage covers were seeder placeholders. They are not restored.
    }
};
