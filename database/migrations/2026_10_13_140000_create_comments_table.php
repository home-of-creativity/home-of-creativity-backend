<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table): void {
            $table->id();
            // report, social_post, calendar_event, photography_booking
            $table->string('subject_type', 32);
            // A Google event id is a string; the rest are numbers kept as text.
            $table->string('subject_id', 1024);
            $table->string('subject_key', 64);
            // The calendar of a Google event, so access follows that calendar.
            $table->string('calendar_id', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('comments')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
