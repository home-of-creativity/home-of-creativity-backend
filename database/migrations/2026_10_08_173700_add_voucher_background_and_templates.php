<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_vouchers', function (Blueprint $table): void {
            $table->longText('background')->nullable()->after('counter_signature');
        });

        Schema::create('financial_voucher_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('kind', 24);
            $table->json('data');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_voucher_templates');

        Schema::table('financial_vouchers', function (Blueprint $table): void {
            $table->dropColumn('background');
        });
    }
};
