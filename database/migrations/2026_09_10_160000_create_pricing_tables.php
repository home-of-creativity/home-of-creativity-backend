<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->text('lead_en')->nullable();
            $table->text('lead_ar')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        Schema::create('pricing_subcategories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('pricing_categories')->cascadeOnDelete();
            $table->string('slug');
            $table->string('name_en');
            $table->string('name_ar');
            $table->text('lead_en')->nullable();
            $table->text('lead_ar')->nullable();
            $table->boolean('one_time')->default(false);
            $table->boolean('lead_in_box')->default(false);
            $table->string('lead_note_en')->nullable();
            $table->string('lead_note_ar')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->unique(['category_id', 'slug']);
        });

        Schema::create('pricing_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subcategory_id')->constrained('pricing_subcategories')->cascadeOnDelete();
            $table->string('slug');
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('subtitle_en');
            $table->string('subtitle_ar');
            $table->unsignedInteger('price_usd')->nullable();
            $table->json('prices')->nullable();
            $table->json('features')->nullable();
            $table->json('reach')->nullable();
            $table->boolean('featured')->default(false);
            $table->string('badge_en')->nullable();
            $table->string('badge_ar')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->unique(['subcategory_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_packages');
        Schema::dropIfExists('pricing_subcategories');
        Schema::dropIfExists('pricing_categories');
    }
};
