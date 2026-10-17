<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('reason', 20)->nullable();
            // support_agent = left the n8n assistant; human_hold = returned after staff pause
            $table->string('source', 32);
            $table->string('channel', 16)->nullable();
            $table->timestamp('asked_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'asked_at']);
            $table->index(['answered_at', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_feedback');
    }
};
