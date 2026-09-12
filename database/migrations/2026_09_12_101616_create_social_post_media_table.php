<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_post_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_post_id')->constrained('social_posts')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->string('kind')->default('image');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['social_post_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_post_media');
    }
};
