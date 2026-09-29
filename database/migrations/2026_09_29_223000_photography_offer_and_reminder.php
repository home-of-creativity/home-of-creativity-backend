<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photography_bookings', function (Blueprint $table): void {
            if (! Schema::hasColumn('photography_bookings', 'proposed_starts_at')) {
                $table->timestamp('proposed_starts_at')->nullable();
            }
            if (! Schema::hasColumn('photography_bookings', 'reminded_at')) {
                $table->timestamp('reminded_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('photography_bookings', function (Blueprint $table): void {
            if (Schema::hasColumn('photography_bookings', 'reminded_at')) {
                $table->dropColumn('reminded_at');
            }
            if (Schema::hasColumn('photography_bookings', 'proposed_starts_at')) {
                $table->dropColumn('proposed_starts_at');
            }
        });
    }
};
