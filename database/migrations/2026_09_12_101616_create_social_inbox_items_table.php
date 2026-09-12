<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_inbox_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained('social_accounts')->cascadeOnDelete();
            $table->string('kind');
            $table->string('external_id');
            $table->string('author_name');
            $table->string('author_handle')->nullable();
            $table->text('body');
            $table->timestamp('occurred_at')->nullable();
            $table->boolean('is_replied')->default(false);
            $table->timestamps();

            $table->unique(['social_account_id', 'external_id']);
            $table->index(['kind', 'is_replied']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_inbox_items');
    }
};
