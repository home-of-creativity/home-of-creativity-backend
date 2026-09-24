<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportGeminiTest extends TestCase
{
    use RefreshDatabase;

    public function test_gemini_edits_one_page_and_keeps_a_memory(): void
    {
        config(['services.gemini.e2e_stub' => true]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/report-memories', ['body' => 'الأسلوب رسمي وقصير'])
            ->assertCreated()
            ->assertJsonPath('data.0.body', 'الأسلوب رسمي وقصير');

        $body = $this->postJson('/api/admin/reports/gemini', [
            'instruction' => 'اختصر الصفحة',
            'scope' => 'page',
            'page' => 2,
            'save_memory' => true,
            'body' => '<section class="hoc-page" data-chrome="1"><p>أول</p></section><section class="hoc-page" data-chrome="0"><p>ثان</p></section>',
        ])->assertOk()
            ->assertJsonPath('data.reply', 'تمت المراجعة')
            ->assertJsonFragment(['body' => 'اختصر الصفحة'])
            ->json('data.body');

        $this->assertStringContainsString('<p>أول</p>', $body);
        $this->assertStringContainsString('مراجعة Gemini', $body);
        $this->assertStringContainsString('data-chrome="0"', $body);
    }
}
