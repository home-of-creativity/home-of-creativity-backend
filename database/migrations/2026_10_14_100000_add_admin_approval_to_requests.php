<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A client's approval waits for an admin out of the client's sight: the request
        // shows awaiting payment to the client while these say whether we approved it.
        Schema::table('requests', function (Blueprint $table): void {
            $table->timestamp('admin_approval_pending_at')->nullable()->after('status');
            $table->timestamp('admin_approved_at')->nullable()->after('admin_approval_pending_at');
            $table->string('admin_approved_by', 64)->nullable()->after('admin_approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table): void {
            $table->dropColumn(['admin_approval_pending_at', 'admin_approved_at', 'admin_approved_by']);
        });
    }
};
