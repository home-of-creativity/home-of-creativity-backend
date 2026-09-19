<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_expenses', function (Blueprint $table): void {
            $table->id();
            $table->decimal('amount', 12, 2);
            $table->string('category', 40);
            $table->string('note', 500)->nullable();
            $table->string('created_by_telegram_id', 32)->nullable();
            $table->timestamp('spent_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_expenses');
    }
};
