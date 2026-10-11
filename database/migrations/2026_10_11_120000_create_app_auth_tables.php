<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('firebase_uid', 128)->nullable()->unique()->after('telegram_user_id');
        });

        Schema::create('app_otp_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->char('phone_hash', 64)->index();
            $table->char('code_hash', 64);
            $table->string('channel', 20)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('app_refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('family')->index();
            $table->char('token_hash', 64)->unique();
            $table->unsignedBigInteger('access_token_id')->nullable();
            $table->string('device_name', 100)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_refresh_tokens');
        Schema::dropIfExists('app_otp_challenges');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['firebase_uid']);
            $table->dropColumn('firebase_uid');
        });
    }
};
