<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('logo_path')->nullable()->after('company_name');
        });

        Schema::table('client_report_slots', function (Blueprint $table): void {
            $table->foreignId('report_calendar_id')->nullable()->after('client_id')
                ->constrained('report_calendars')->nullOnDelete();
            $table->string('color', 7)->nullable()->after('kind');
        });

        Schema::create('client_report_slot_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_report_slot_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('name', 190)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_report_slot_images');
        Schema::table('client_report_slots', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('report_calendar_id');
            $table->dropColumn('color');
        });
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('logo_path');
        });
    }
};
