<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->timestamp('invoice_sent_at')->nullable();
        });

        $sent = DB::table('invoices')
            ->whereNotNull('issued_at')
            ->where('kind', '!=', 'received')
            ->selectRaw('request_id, MIN(issued_at) as sent_at')
            ->groupBy('request_id')
            ->get();

        foreach ($sent as $row) {
            DB::table('requests')
                ->where('id', $row->request_id)
                ->whereNull('invoice_sent_at')
                ->update(['invoice_sent_at' => $row->sent_at]);
        }
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->dropColumn('invoice_sent_at');
        });
    }
};
