<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('requests', 'work_plan')) {
                $table->json('work_plan')->nullable()->after('ai_analysis');
            }
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            if (Schema::hasColumn('requests', 'work_plan')) {
                $table->dropColumn('work_plan');
            }
        });
    }
};
