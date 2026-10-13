<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_vouchers', function (Blueprint $table): void {
            $table->string('title', 120)->nullable()->after('kind');
            $table->string('signer_title', 60)->nullable()->after('signer_name');
            $table->string('counter_signer_title', 60)->nullable()->after('counter_signer_name');
        });
    }

    public function down(): void
    {
        Schema::table('financial_vouchers', function (Blueprint $table): void {
            $table->dropColumn(['title', 'signer_title', 'counter_signer_title']);
        });
    }
};
