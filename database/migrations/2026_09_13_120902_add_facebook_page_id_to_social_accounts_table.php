<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->string('facebook_page_id')->nullable()->after('page_id');
            $table->index('facebook_page_id');
        });

        DB::table('social_accounts')
            ->where('platform', 'facebook')
            ->whereNull('facebook_page_id')
            ->update(['facebook_page_id' => DB::raw('page_id')]);
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropIndex(['facebook_page_id']);
            $table->dropColumn('facebook_page_id');
        });
    }
};
