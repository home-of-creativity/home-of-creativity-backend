<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_projects', function (Blueprint $table) {
            $table->string('website_url')->nullable()->after('summary_ar');
            $table->json('social_links')->nullable()->after('website_url');
        });

        Schema::create('portfolio_project_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_project_id')->constrained('portfolio_projects')->cascadeOnDelete();
            $table->string('image_path');
            $table->string('alt_en')->nullable();
            $table->string('alt_ar')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('featured')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_project_images');

        Schema::table('portfolio_projects', function (Blueprint $table) {
            $table->dropColumn(['website_url', 'social_links']);
        });
    }
};
