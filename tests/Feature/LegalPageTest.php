<?php

namespace Tests\Feature;

use App\Models\LegalPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LegalPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_legal_index_and_show_return_pages(): void
    {
        LegalPage::factory()->create();
        LegalPage::factory()->terms()->create();

        $this->getJson('/api/legal')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'privacy')
            ->assertJsonPath('data.1.slug', 'terms');

        $this->getJson('/api/legal/privacy')
            ->assertOk()
            ->assertJsonPath('data.title_en', 'Privacy Policy')
            ->assertJsonPath('data.sections.0.id', 'intro');
    }

    public function test_privacy_intro_migration_lists_every_office_and_keeps_other_text(): void
    {
        LegalPage::factory()->create([
            'sections' => [
                [
                    'id' => 'intro',
                    'heading_ar' => 'مقدمة',
                    'heading_en' => 'Introduction',
                    'html_ar' => '<p>بيت الإبداع وكالة هوية بصرية تعمل من دمشق. نص يحرره الفريق.</p>',
                    'html_en' => '<p>Home of Creativity is a brand studio based in Damascus. Staff text.</p>',
                ],
                ['id' => 'contact', 'html_ar' => '<p>بيت الإبداع — دمشق، الحمراء.</p>', 'html_en' => '<p>Damascus, Al Hamra.</p>'],
            ],
        ]);

        (require database_path('migrations/2026_10_04_090000_privacy_intro_lists_all_offices.php'))->up();

        $sections = LegalPage::query()->where('slug', 'privacy')->firstOrFail()->sections;
        $this->assertSame(
            '<p>بيت الإبداع وكالة هوية بصرية بمكاتب في دمشق (الحمراء) والرياض (المربّع) والإمارات العربية المتحدة. نص يحرره الفريق.</p>',
            $sections[0]['html_ar'],
        );
        $this->assertStringContainsString('with offices in Damascus (Al Hamra), Riyadh (Al Murabba) and the United Arab Emirates. Staff text.', $sections[0]['html_en']);
        $this->assertSame('<p>بيت الإبداع — دمشق، الحمراء.</p>', $sections[1]['html_ar']);
    }

    public function test_telegram_bot_migration_drops_only_that_contact_line(): void
    {
        LegalPage::factory()->create([
            'sections' => [[
                'id' => 'contact',
                'html_ar' => "<ul>
<li>واتساب: <a href=\"https://wa.me/963954187154\">+963</a></li>
<li>تيليجرام: <a href=\"https://t.me/pro_design_perfect_bot\">بوت العملاء</a></li>
<li>البريد: admin@hoc.agency</li>
</ul>",
                'html_en' => "<ul>
<li>Telegram: <a href=\"https://t.me/pro_design_perfect_bot\">client bot</a></li>
<li>Email: admin@hoc.agency</li>
</ul>",
            ]],
        ]);

        (require database_path('migrations/2026_10_04_130000_remove_telegram_bot_link_from_legal_pages.php'))->up();

        $section = LegalPage::query()->where('slug', 'privacy')->firstOrFail()->sections[0];
        $this->assertSame("<ul>
<li>واتساب: <a href=\"https://wa.me/963954187154\">+963</a></li>
<li>البريد: admin@hoc.agency</li>
</ul>", $section['html_ar']);
        $this->assertSame("<ul>
<li>Email: admin@hoc.agency</li>
</ul>", $section['html_en']);
    }

    public function test_guest_cannot_update_legal_pages(): void
    {
        LegalPage::factory()->create();

        $this->putJson('/api/admin/legal/privacy', [
            'title_ar' => 'سياسة',
            'title_en' => 'Privacy',
            'sections' => [[
                'id' => 'intro',
                'heading_ar' => 'مقدمة',
                'heading_en' => 'Intro',
                'html_ar' => '<p>نص</p>',
                'html_en' => '<p>Text</p>',
            ]],
        ])->assertUnauthorized();
    }

    public function test_admin_can_update_legal_page_and_scripts_are_stripped(): void
    {
        $page = LegalPage::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/legal/privacy', [
            'title_ar' => 'سياسة الخصوصية المحدّثة',
            'title_en' => 'Updated Privacy Policy',
            'sections' => [[
                'heading_ar' => 'الاستخدام',
                'heading_en' => 'How we use',
                'html_ar' => '<p>آمن</p><script>alert(1)</script>',
                'html_en' => '<p>Safe</p><script>alert(1)</script><a href="javascript:alert(1)">x</a>',
            ]],
        ])->assertOk()
            ->assertJsonPath('data.title_en', 'Updated Privacy Policy')
            ->assertJsonPath('data.sections.0.id', 'how-we-use')
            ->assertJsonPath('data.sections.0.heading_en', 'How we use');

        $page->refresh();
        $htmlEn = (string) $page->sections[0]['html_en'];
        $this->assertStringContainsString('<p>Safe</p>', $htmlEn);
        $this->assertStringNotContainsString('<script', $htmlEn);
        $this->assertStringNotContainsString('javascript:', $htmlEn);
    }

    public function test_admin_update_requires_sections(): void
    {
        LegalPage::factory()->create();
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->putJson('/api/admin/legal/privacy', [
            'title_ar' => 'سياسة',
            'title_en' => 'Privacy',
            'sections' => [],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('sections');
    }

    public function test_unknown_slug_returns_not_found(): void
    {
        $this->getJson('/api/legal/cookies')->assertNotFound();
    }

    public function test_missing_pages_still_return_builtin_copy(): void
    {
        $this->getJson('/api/legal')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'privacy')
            ->assertJsonPath('data.1.slug', 'terms');

        $this->getJson('/api/legal/terms')
            ->assertOk()
            ->assertJsonPath('data.title_en', 'Terms of Use')
            ->assertJsonPath('data.sections.0.id', 'agreement');

        $this->getJson('/api/legal/privacy')
            ->assertOk()
            ->assertJsonPath('data.sections.0.id', 'about');
    }

    public function test_admin_show_uses_builtin_copy_when_missing(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->getJson('/api/admin/legal/terms')
            ->assertOk()
            ->assertJsonPath('data.slug', 'terms')
            ->assertJsonPath('data.title_en', 'Terms of Use')
            ->assertJsonPath('data.sections.0.id', 'agreement');

        $this->getJson('/api/admin/legal/privacy')
            ->assertOk()
            ->assertJsonPath('data.slug', 'privacy')
            ->assertJsonPath('data.sections.0.heading_en', 'Who we are');
    }

    public function test_saving_terms_does_not_change_privacy(): void
    {
        LegalPage::factory()->create();
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->putJson('/api/admin/legal/terms', [
            'title_ar' => 'شروط الاستخدام',
            'title_en' => 'Terms of Use',
            'sections' => [[
                'id' => 'agreement',
                'heading_ar' => 'الاتفاق',
                'heading_en' => 'The agreement',
                'html_ar' => '<p>شروط</p>',
                'html_en' => '<p>Terms body</p>',
            ]],
        ])->assertOk()->assertJsonPath('data.slug', 'terms');

        $this->assertDatabaseHas('legal_pages', ['slug' => 'privacy']);
        $privacy = LegalPage::query()->where('slug', 'privacy')->first();
        $this->assertNotNull($privacy);
        $this->assertStringNotContainsString('Terms body', json_encode($privacy->sections));
    }

    public function test_admin_can_create_legal_page_without_seeder(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->putJson('/api/admin/legal/terms', [
            'title_ar' => 'شروط الاستخدام',
            'title_en' => 'Terms of Use',
            'sections' => [[
                'heading_ar' => '',
                'heading_en' => '',
                'html_ar' => '<p>نص عربي</p>',
                'html_en' => '<p>English text</p>',
            ]],
        ])->assertOk()
            ->assertJsonPath('data.slug', 'terms');

        $this->assertDatabaseHas('legal_pages', ['slug' => 'terms']);
    }

    public function test_scaffold_defaults_exist_for_reference_copy(): void
    {
        $privacy = LegalPage::scaffold('privacy');
        $this->assertSame('privacy', $privacy['slug']);
        $this->assertSame('', $privacy['sections'][0]['html_en']);
    }
}
