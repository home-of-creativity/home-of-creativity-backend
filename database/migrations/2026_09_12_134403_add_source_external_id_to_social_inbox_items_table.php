<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_inbox_items', function (Blueprint $table) {
            $table->string('source_external_id')->nullable()->after('external_id');
            $table->index(['social_account_id', 'source_external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('social_inbox_items', function (Blueprint $table) {
            $table->dropIndex(['social_account_id', 'source_external_id']);
            $table->dropColumn('source_external_id');
        });
    }
};
