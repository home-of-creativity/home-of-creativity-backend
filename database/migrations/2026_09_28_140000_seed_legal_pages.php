<?php

use App\Models\LegalPage;
use App\Support\LegalDefaults;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        foreach (LegalDefaults::pages() as $page) {
            $existing = LegalPage::query()->where('slug', $page['slug'])->first();

            if ($existing === null) {
                LegalPage::query()->create($page);

                continue;
            }

            if ($this->isPlaceholderOnly($existing)) {
                $existing->fill([
                    'title_ar' => $page['title_ar'],
                    'title_en' => $page['title_en'],
                    'sections' => $page['sections'],
                ])->save();
            }
        }
    }

    public function down(): void
    {
        // Keep published legal copy. Removing it would blank /privacy and /terms.
    }

    private function isPlaceholderOnly(LegalPage $page): bool
    {
        $text = '';

        foreach ($page->sections ?? [] as $section) {
            if (! is_array($section)) {
                continue;
            }

            foreach (['heading_ar', 'heading_en', 'html_ar', 'html_en'] as $key) {
                $text .= ' '.trim(strip_tags((string) ($section[$key] ?? '')));
            }
        }

        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if ($text === '') {
            return true;
        }

        return in_array($text, [
            'سياسة الخصوصية',
            'Privacy policy',
            'سياسة الخصوصية Privacy policy',
            'Privacy policy سياسة الخصوصية',
        ], true);
    }
};
