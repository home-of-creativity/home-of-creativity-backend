<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odoo_lead_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('request_id')->nullable()->constrained('requests')->nullOnDelete();
            $table->string('dedupe_key')->nullable()->unique();
            $table->text('body');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['posted_at', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odoo_lead_notes');
    }
};
