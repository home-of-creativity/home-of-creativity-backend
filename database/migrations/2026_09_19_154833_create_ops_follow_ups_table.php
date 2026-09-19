<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ops_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 40);
            $table->string('dedupe_key', 120);
            $table->nullableMorphs('subject');
            $table->timestamp('sent_at')->nullable();
            $table->unsignedTinyInteger('send_count')->default(1);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'dedupe_key']);
            $table->index(['kind', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ops_follow_ups');
    }
};
