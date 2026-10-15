<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_vouchers', function (Blueprint $table): void {
            $table->decimal('remaining', 14, 2)->nullable()->after('amount');
            $table->boolean('show_remaining')->default(false)->after('remaining');
        });
    }

    public function down(): void
    {
        Schema::table('financial_vouchers', function (Blueprint $table): void {
            $table->dropColumn(['remaining', 'show_remaining']);
        });
    }
};
