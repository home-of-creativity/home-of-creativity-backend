<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_projects', function (Blueprint $table) {
            if (! Schema::hasColumn('portfolio_projects', 'body_en')) {
                $table->longText('body_en')->nullable()->after('summary_ar');
            }
            if (! Schema::hasColumn('portfolio_projects', 'body_ar')) {
                $table->longText('body_ar')->nullable()->after('body_en');
            }
        });

        if (Schema::hasTable('portfolio_project_related')) {
            return;
        }

        Schema::create('portfolio_project_related', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_project_id')->constrained('portfolio_projects')->cascadeOnDelete();
            $table->foreignId('related_project_id')->constrained('portfolio_projects')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unique(['portfolio_project_id', 'related_project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_project_related');

        Schema::table('portfolio_projects', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['body_en', 'body_ar'],
                fn (string $column): bool => Schema::hasColumn('portfolio_projects', $column),
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
