<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('platform');
            $table->string('name');
            $table->string('handle')->nullable();
            $table->string('page_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('connection_status')->default('pending');
            $table->text('last_error')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('platform');
            $table->index('is_active');
            $table->index('connection_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
