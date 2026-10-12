<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table): void {
            $table->string('ticket_number', 30)->nullable()->unique()->after('client_id');
            $table->timestamp('ticket_opened_at')->nullable()->after('reviewed_at');
            // The chat the complaint came from, so the reply goes back to the same bot.
            $table->string('chat_id', 120)->nullable()->after('audio_mime');
        });

        Schema::create('complaint_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('complaint_id')->constrained('complaints')->cascadeOnDelete();
            $table->string('author', 10);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 20);
            $table->text('body');
            $table->boolean('delivered')->default(false);
            $table->timestamps();
        });

        DB::table('complaints')->where('status', 'reviewed')->update(['status' => 'resolved']);
    }

    public function down(): void
    {
        DB::table('complaints')->where('status', 'resolved')->update(['status' => 'reviewed']);
        DB::table('complaints')->where('status', 'in_progress')->update(['status' => 'open']);
        Schema::dropIfExists('complaint_messages');
        Schema::table('complaints', function (Blueprint $table): void {
            $table->dropUnique(['ticket_number']);
            $table->dropColumn(['ticket_number', 'ticket_opened_at', 'chat_id']);
        });
    }
};
