<?php

namespace Tests\Feature;

use App\Models\LegalPage;
use App\Models\User;
use App\Support\LegalDefaults;
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
            ->assertJsonPath('data.sections.0.id', 'how-we-use');

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

    public function test_defaults_include_privacy_and_terms_sections(): void
    {
        $pages = LegalDefaults::pages();
        $this->assertSame(['privacy', 'terms'], array_column($pages, 'slug'));
        $this->assertNotEmpty($pages[0]['sections']);
        $this->assertNotEmpty($pages[1]['sections']);
    }
}
