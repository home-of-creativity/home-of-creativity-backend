<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_vouchers', function (Blueprint $table): void {
            $table->timestamp('sent_at')->nullable()->after('signed_at');
            $table->string('sent_to', 255)->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('financial_vouchers', function (Blueprint $table): void {
            $table->dropColumn(['sent_at', 'sent_to']);
        });
    }
};
