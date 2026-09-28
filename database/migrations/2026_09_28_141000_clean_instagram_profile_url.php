<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        DB::table('contact_channels')
            ->where('platform', 'instagram')
            ->where('url', 'like', '%utm_source=qr%')
            ->update(['url' => 'https://www.instagram.com/homeofcreativity.sy/']);
    }

    public function down(): void
    {
        // The tracking link is not restored.
    }
};
