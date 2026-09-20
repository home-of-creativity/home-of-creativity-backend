<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drive_deliveries', function (Blueprint $table) {
            $table->timestamp('client_approved_at')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('drive_deliveries', function (Blueprint $table) {
            $table->dropColumn('client_approved_at');
        });
    }
};
