<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odoo_invoices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('odoo_id')->unique();
            $table->string('name')->nullable();
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->string('partner_name')->nullable()->index();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('request_id')->nullable()->constrained('requests')->nullOnDelete();
            $table->decimal('amount_total', 14, 2)->default(0);
            $table->decimal('amount_residual', 14, 2)->default(0);
            $table->string('currency', 12)->nullable();
            $table->string('state', 20)->index();
            $table->string('payment_state', 20)->nullable()->index();
            $table->date('invoice_date')->nullable()->index();
            $table->date('invoice_date_due')->nullable();
            $table->string('invoice_origin')->nullable();
            $table->string('ref')->nullable();
            $table->timestamp('odoo_write_date')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odoo_invoices');
    }
};
