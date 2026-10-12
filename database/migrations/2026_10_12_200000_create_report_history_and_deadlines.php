<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per editing session, publish, restore, attachment change, or delete.
        // client_id stays after the report is deleted so the client's history keeps the delete.
        Schema::create('client_report_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_report_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 32);
            $table->string('title');
            $table->longText('body')->nullable();
            $table->string('document_path')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('saves')->default(1);
            $table->json('detail')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamps();
            $table->index(['client_report_id', 'id']);
            $table->index(['client_id', 'started_at']);
        });

        // Due dates for client reports. Times are Damascus wall clock; a null time is all day.
        Schema::create('report_deadlines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->date('starts_on');
            $table->time('due_time')->nullable();
            $table->string('repeat', 16)->default('none');
            $table->date('repeat_until')->nullable();
            $table->string('color', 16)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('starts_on');
        });

        // Per-occurrence changes: done (with the report that answered it), skipped, or moved.
        Schema::create('report_deadline_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_deadline_id')->constrained()->cascadeOnDelete();
            $table->date('occurs_on');
            $table->date('moved_on')->nullable();
            $table->time('moved_time')->nullable();
            $table->boolean('skipped')->default(false);
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_report_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['report_deadline_id', 'occurs_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_deadline_occurrences');
        Schema::dropIfExists('report_deadlines');
        Schema::dropIfExists('client_report_versions');
    }
};
