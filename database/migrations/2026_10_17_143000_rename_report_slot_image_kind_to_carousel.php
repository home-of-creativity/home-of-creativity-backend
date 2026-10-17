<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('client_report_slots')->where('kind', 'image')->update(['kind' => 'carousel']);
    }

    public function down(): void
    {
        DB::table('client_report_slots')->where('kind', 'carousel')->update(['kind' => 'image']);
    }
};
