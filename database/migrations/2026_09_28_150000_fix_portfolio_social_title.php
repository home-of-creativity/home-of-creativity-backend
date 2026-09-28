<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('portfolio_projects')
            ->where('title_ar', 'السوشل والمحتوى والحملات')
            ->update(['title_ar' => 'السوشيال والمحتوى والحملات']);
    }

    public function down(): void
    {
        DB::table('portfolio_projects')
            ->where('title_en', 'Social, film & campaigns')
            ->where('title_ar', 'السوشيال والمحتوى والحملات')
            ->update(['title_ar' => 'السوشل والمحتوى والحملات']);
    }
};
