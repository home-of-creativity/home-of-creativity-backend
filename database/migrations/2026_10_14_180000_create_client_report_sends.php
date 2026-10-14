<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_report_sends', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // email or whatsapp
            $table->string('channel', 16);
            $table->string('recipient', 190);
            $table->text('message')->nullable();
            $table->boolean('delivered');
            $table->timestamps();

            $table->index(['client_report_id', 'id']);
        });

        Schema::table('comments', function (Blueprint $table): void {
            $table->text('body')->nullable()->change();
            // image or audio
            $table->string('attachment_kind', 8)->nullable()->after('body');
            $table->string('attachment_path')->nullable()->after('attachment_kind');
            $table->string('attachment_name', 190)->nullable()->after('attachment_path');
            $table->string('attachment_mime', 100)->nullable()->after('attachment_name');
            $table->unsignedInteger('attachment_size')->nullable()->after('attachment_mime');
        });

        // Roles that publish reports may also send them; a role with the whole feature already can.
        foreach (DB::table('roles')->get(['id', 'abilities']) as $role) {
            $abilities = json_decode((string) $role->abilities, true);
            if (! is_array($abilities) || ! in_array('ops.reports.update', $abilities, true) || in_array('ops.reports.send', $abilities, true)) {
                continue;
            }
            $abilities[] = 'ops.reports.send';
            DB::table('roles')->where('id', $role->id)->update(['abilities' => json_encode(array_values($abilities))]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->get(['id', 'abilities']) as $role) {
            $abilities = json_decode((string) $role->abilities, true);
            if (is_array($abilities) && in_array('ops.reports.send', $abilities, true)) {
                DB::table('roles')->where('id', $role->id)->update(['abilities' => json_encode(array_values(array_diff($abilities, ['ops.reports.send'])))]);
            }
        }

        Schema::table('comments', function (Blueprint $table): void {
            $table->dropColumn(['attachment_kind', 'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size']);
        });

        Schema::dropIfExists('client_report_sends');
    }
};
