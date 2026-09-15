<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_inbox_items', function (Blueprint $table) {
            $table->foreignId('social_post_id')->nullable()->after('social_account_id')->constrained('social_posts')->nullOnDelete();
            $table->text('source_body')->nullable()->after('source_external_id');
            $table->string('source_permalink')->nullable()->after('source_body');
            $table->string('source_preview_url', 2048)->nullable()->after('source_permalink');
            $table->string('source_media_type')->nullable()->after('source_preview_url');
        });
    }

    public function down(): void
    {
        Schema::table('social_inbox_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('social_post_id');
            $table->dropColumn([
                'source_body',
                'source_permalink',
                'source_preview_url',
                'source_media_type',
            ]);
        });
    }
};
