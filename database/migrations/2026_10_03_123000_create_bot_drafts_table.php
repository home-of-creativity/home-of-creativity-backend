<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_drafts', function (Blueprint $table) {
            $table->id();
            $table->string('bot', 20)->default('client');
            $table->string('telegram_user_id', 40);
            $table->json('payload');
            $table->timestamps();

            $table->unique(['bot', 'telegram_user_id']);
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_drafts');
    }
};
