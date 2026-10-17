<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_report_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            // image, post, or reel. A calendar plan only: nothing here is published.
            $table->string('kind', 16);
            $table->dateTime('scheduled_at');
            $table->text('caption')->nullable();
            $table->string('image_path')->nullable();
            $table->string('image_name', 190)->nullable();
            $table->timestamps();

            $table->index(['client_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_report_slots');
    }
};
