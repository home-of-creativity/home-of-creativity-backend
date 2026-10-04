<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The showcase client "Abo shakir" gives logo alt text such as «شعار Abo shakir». Use the
 * brand's Arabic name, as its sister brand «جدو شاكر» already does. Only that exact name changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('showcase_clients')->where('name', 'Abo shakir')->update(['name' => 'أبو شاكر', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('showcase_clients')->where('name', 'أبو شاكر')->update(['name' => 'Abo shakir', 'updated_at' => now()]);
    }
};
