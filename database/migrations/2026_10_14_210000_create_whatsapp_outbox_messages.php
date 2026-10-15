<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_outbox_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('recipient', 64);
            // text, choice, document or image
            $table->string('kind', 16);
            $table->text('body')->nullable();
            $table->json('buttons')->nullable();
            $table->string('file_path')->nullable();
            $table->string('filename', 190)->nullable();
            $table->timestamps();

            $table->index(['recipient', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_outbox_messages');
    }
};
