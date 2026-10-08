<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_vouchers', function (Blueprint $table): void {
            $table->id();
            $table->string('serial', 24)->unique();
            $table->string('kind', 24);
            $table->string('party_name', 160);
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('currency', 8)->default('USD');
            $table->string('amount_words', 240)->nullable();
            $table->date('issued_on');
            $table->text('purpose')->nullable();
            $table->string('reference', 120)->nullable();
            $table->json('lines')->nullable();
            $table->string('signer_name', 120)->nullable();
            $table->string('counter_signer_name', 120)->nullable();
            $table->longText('signature')->nullable();
            $table->longText('counter_signature')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_vouchers');
    }
};
