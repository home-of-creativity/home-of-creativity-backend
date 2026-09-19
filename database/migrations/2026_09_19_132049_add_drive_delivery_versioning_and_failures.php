<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drive_deliveries', function (Blueprint $table): void {
            if (! Schema::hasColumn('drive_deliveries', 'drive_modified_at')) {
                $table->timestamp('drive_modified_at')->nullable()->after('sent_at');
            }
            if (! Schema::hasColumn('drive_deliveries', 'content_hash')) {
                $table->string('content_hash', 64)->nullable()->after('drive_modified_at');
            }
            if (! Schema::hasColumn('drive_deliveries', 'telegram_message_id')) {
                $table->unsignedBigInteger('telegram_message_id')->nullable()->after('content_hash');
            }
            if (! Schema::hasColumn('drive_deliveries', 'failed_at')) {
                $table->timestamp('failed_at')->nullable()->after('telegram_message_id');
            }
            if (! Schema::hasColumn('drive_deliveries', 'fail_reason')) {
                $table->string('fail_reason', 255)->nullable()->after('failed_at');
            }
        });

        Schema::table('requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('requests', 'drive_last_activity_at')) {
                $table->timestamp('drive_last_activity_at')->nullable()->after('google_drive_folder_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('drive_deliveries', function (Blueprint $table): void {
            foreach (['drive_modified_at', 'content_hash', 'telegram_message_id', 'failed_at', 'fail_reason'] as $column) {
                if (Schema::hasColumn('drive_deliveries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('requests', function (Blueprint $table): void {
            if (Schema::hasColumn('requests', 'drive_last_activity_at')) {
                $table->dropColumn('drive_last_activity_at');
            }
        });
    }
};
