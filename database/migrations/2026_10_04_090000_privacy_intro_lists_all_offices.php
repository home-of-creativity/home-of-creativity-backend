<?php

use App\Models\LegalPage;
use Illuminate\Database\Migrations\Migration;

/**
 * The stored privacy policy opened with "an agency working from Damascus". HOC has offices in
 * Damascus, Riyadh and the UAE. Replace that one sentence only; staff edits elsewhere stay.
 */
return new class extends Migration
{
    private const REPLACEMENTS = [
        'html_ar' => [
            'وكالة هوية بصرية تعمل من دمشق.',
            'وكالة هوية بصرية بمكاتب في دمشق (الحمراء) والرياض (المربّع) والإمارات العربية المتحدة.',
        ],
        'html_en' => [
            'is a brand studio based in Damascus.',
            'is a brand studio with offices in Damascus (Al Hamra), Riyadh (Al Murabba) and the United Arab Emirates.',
        ],
    ];

    public function up(): void
    {
        $page = LegalPage::query()->where('slug', 'privacy')->first();
        if (! $page instanceof LegalPage) {
            return;
        }

        $changed = false;
        $sections = [];
        foreach ($page->sections ?? [] as $section) {
            if (is_array($section)) {
                foreach (self::REPLACEMENTS as $key => [$old, $new]) {
                    if (isset($section[$key]) && str_contains((string) $section[$key], $old)) {
                        $section[$key] = str_replace($old, $new, (string) $section[$key]);
                        $changed = true;
                    }
                }
            }
            $sections[] = $section;
        }

        if ($changed) {
            $page->sections = $sections;
            $page->save();
        }
    }

    public function down(): void
    {
        // Text-only fix; nothing to undo.
    }
};
