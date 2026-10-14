<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extra calendars on the reports calendar. Each one is its own view permission
        // (`ops.report_calendar.{id}` in a role's abilities); deadlines without a
        // calendar stay on the main calendar that every reports viewer sees.
        Schema::create('report_calendars', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('color', 7)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('report_deadlines', function (Blueprint $table): void {
            $table->foreignId('report_calendar_id')->nullable()->after('client_id')
                ->constrained('report_calendars')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('report_deadlines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('report_calendar_id');
        });
        Schema::dropIfExists('report_calendars');
    }
};
