<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photography_bookings', function (Blueprint $table): void {
            $table->timestamp('hour_reminded_at')->nullable()->after('reminded_at');
        });

        Schema::create('request_ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->unique()->constrained('requests')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('reason', 20)->nullable();
            // whatsapp, telegram or email
            $table->string('channel', 16)->nullable();
            $table->timestamp('asked_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->index(['answered_at', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_ratings');

        Schema::table('photography_bookings', function (Blueprint $table): void {
            $table->dropColumn('hour_reminded_at');
        });
    }
};
