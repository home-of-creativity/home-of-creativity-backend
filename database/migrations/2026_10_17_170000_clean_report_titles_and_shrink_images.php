<?php

use App\Support\ReportTitle;
use App\Support\ShrinkDocxImages;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staging only. Cleans characters a file name cannot contain, and shrinks oversized
 * pictures inside report.docx after copying the file beside the original.
 * No report row is deleted. A failed shrink leaves the original file.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! app()->environment('staging') || ! Schema::hasTable('client_reports')) {
            return;
        }

        DB::table('client_reports')->orderBy('id')->chunkById(50, function ($rows): void {
            foreach ($rows as $row) {
                $clean = ReportTitle::clean($row->title ?? '');
                if ($clean !== '' && $clean !== $row->title) {
                    DB::table('client_reports')->where('id', $row->id)->update(['title' => $clean]);
                }

                $path = $row->document_path ?? null;
                if (! is_string($path) || $path === '') {
                    continue;
                }

                try {
                    app(ShrinkDocxImages::class)->shrink('local', $path);
                } catch (Throwable) {
                    // The original file stays. The report row is not marked broken.
                }
            }
        });
    }

    public function down(): void
    {
        // Titles are cleaned in place. The .bak copy beside each rewritten file is the picture backup.
    }
};
