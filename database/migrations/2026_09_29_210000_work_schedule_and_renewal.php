<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_packages', function (Blueprint $table): void {
            if (! Schema::hasColumn('pricing_packages', 'work_lines')) {
                $table->json('work_lines')->nullable();
            }
        });

        Schema::table('requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('requests', 'draft_work_lines')) {
                $table->json('draft_work_lines')->nullable();
            }
            if (! Schema::hasColumn('requests', 'plan_confirmed_at')) {
                $table->timestamp('plan_confirmed_at')->nullable();
            }
            if (! Schema::hasColumn('requests', 'client_due_at')) {
                $table->timestamp('client_due_at')->nullable();
            }
            if (! Schema::hasColumn('requests', 'schedule_extension_reason')) {
                $table->string('schedule_extension_reason')->nullable();
            }
            if (! Schema::hasColumn('requests', 'edit_rounds')) {
                $table->unsignedTinyInteger('edit_rounds')->default(0);
            }
            if (! Schema::hasColumn('requests', 'edit_estimate_hours')) {
                $table->unsignedSmallInteger('edit_estimate_hours')->nullable();
            }
        });

        Schema::table('clickup_tasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('clickup_tasks', 'planned_hours')) {
                $table->unsignedSmallInteger('planned_hours')->nullable();
            }
            if (! Schema::hasColumn('clickup_tasks', 'period_key')) {
                $table->string('period_key')->nullable();
            }
        });

        if (! Schema::hasTable('photography_bookings')) {
            Schema::create('photography_bookings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('request_id')->constrained('requests')->cascadeOnDelete();
                $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
                $table->timestamp('starts_at');
                $table->timestamp('ends_at');
                $table->string('status')->default('held');
                $table->timestamps();
                $table->index(['starts_at', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('photography_bookings');

        Schema::table('clickup_tasks', function (Blueprint $table): void {
            if (Schema::hasColumn('clickup_tasks', 'period_key')) {
                $table->dropColumn('period_key');
            }
            if (Schema::hasColumn('clickup_tasks', 'planned_hours')) {
                $table->dropColumn('planned_hours');
            }
        });

        Schema::table('requests', function (Blueprint $table): void {
            foreach ([
                'edit_estimate_hours',
                'edit_rounds',
                'schedule_extension_reason',
                'client_due_at',
                'plan_confirmed_at',
                'draft_work_lines',
            ] as $column) {
                if (Schema::hasColumn('requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('pricing_packages', function (Blueprint $table): void {
            if (Schema::hasColumn('pricing_packages', 'work_lines')) {
                $table->dropColumn('work_lines');
            }
        });
    }
};
