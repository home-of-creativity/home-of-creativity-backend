<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('cover_path')->nullable();
            $table->string('header')->nullable();
            $table->string('footer')->nullable();
            $table->longText('body');
            $table->string('drive_file_id')->nullable();
            $table->string('drive_url')->nullable();
            $table->timestamps();
        });

        Schema::create('client_report_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_report_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('drive_file_id')->nullable();
            $table->string('drive_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_report_attachments');
        Schema::dropIfExists('client_reports');
    }
};
