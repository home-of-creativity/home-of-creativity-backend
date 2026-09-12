<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_inbox_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_inbox_item_id')->constrained('social_inbox_items')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->string('external_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index('social_inbox_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_inbox_replies');
    }
};
