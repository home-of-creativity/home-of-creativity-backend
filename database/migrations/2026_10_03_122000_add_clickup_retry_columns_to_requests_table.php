<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->text('clickup_error')->nullable();
            $table->unsignedSmallInteger('clickup_attempts')->default(0);
            $table->timestamp('clickup_failed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('requests', function (Blueprint $table) {
            $table->dropColumn(['clickup_error', 'clickup_attempts', 'clickup_failed_at']);
        });
    }
};
