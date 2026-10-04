<?php

use App\Models\LegalPage;
use Illuminate\Database\Migrations\Migration;

/**
 * The privacy and terms contact lists link t.me/pro_design_perfect_bot. That handle was removed
 * from the site until HOC confirms the right one (TODO(HOC) in design lib/base-path.ts). Drop
 * only the list items that link it; staff edits elsewhere stay.
 */
return new class extends Migration
{
    private const LIST_ITEM = '~[ \t]*<li>(?:(?!</li>).)*?t\.me/pro_design_perfect_bot(?:(?!</li>).)*?</li>\R?~su';

    public function up(): void
    {
        foreach (LegalPage::query()->get() as $page) {
            $changed = false;
            $sections = [];
            foreach ($page->sections ?? [] as $section) {
                if (is_array($section)) {
                    foreach (['html_ar', 'html_en'] as $key) {
                        if (! isset($section[$key])) {
                            continue;
                        }
                        $html = preg_replace(self::LIST_ITEM, '', (string) $section[$key]);
                        if (is_string($html) && $html !== $section[$key]) {
                            $section[$key] = $html;
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
    }

    public function down(): void
    {
        // Text-only removal; the link comes back by editing the page in the dashboard.
    }
};
