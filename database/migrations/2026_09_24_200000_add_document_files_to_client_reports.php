<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_reports', function (Blueprint $table) {
            $table->string('document_path')->nullable()->after('body');
            $table->string('pdf_path')->nullable()->after('document_path');
            $table->string('drive_document_id')->nullable()->after('drive_url');
            $table->string('drive_document_url')->nullable()->after('drive_document_id');
            $table->timestamp('published_at')->nullable()->after('drive_document_url');
        });
    }

    public function down(): void
    {
        Schema::table('client_reports', function (Blueprint $table) {
            $table->dropColumn(['document_path', 'pdf_path', 'drive_document_id', 'drive_document_url', 'published_at']);
        });
    }
};
