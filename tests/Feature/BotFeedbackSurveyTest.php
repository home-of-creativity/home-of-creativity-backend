<?php

namespace Tests\Feature;

use App\Actions\BotFeedbackSurvey;
use App\Actions\WhatsAppSupportAgent;
use App\Models\BotFeedback;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BotFeedbackSurveyTest extends TestCase
{
    use RefreshDatabase;

    private const AGENT = 'http://n8n.test/webhook/hoc-support-agent';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.transport' => 'web',
            'services.whatsapp.enabled' => false,
            'services.whatsapp.web_url' => 'http://wa-web.test',
            'services.whatsapp.web_secret' => 'web-secret',
            'services.whatsapp.management_numbers' => ['+963944000900'],
            'services.n8n.support_agent_url' => self::AGENT,
            'services.n8n.webhook_secret' => 'n8n-secret',
            'services.gemini.api_key' => '',
            'services.gemini.e2e_stub' => false,
        ]);
        Mail::fake();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-10-17 22:00:00', 'Asia/Damascus'));
        Client::query()->create([
            'name' => 'Nour',
            'phone' => '+963922222222',
            'company_name' => 'Nour Co',
            'telegram_user_id' => 'wa:963922222222',
            'locale' => 'ar',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_leaving_the_agent_asks_for_bot_feedback(): void
    {
        Http::fake([
            self::AGENT => Http::response(['output' => [
                'reply' => 'أكيد.',
                'handoff' => false,
                'handoff_reason' => '',
                'action' => ['type' => 'none'],
            ]], 200),
            'http://wa-web.test/*' => Http::response(['id' => 'wa-1'], 200),
        ]);

        $this->send('بدي احكي مع موظف', 'm1');
        $this->send('القائمة', 'm2');

        $this->assertFalse(app(WhatsAppSupportAgent::class)->active('963922222222'));
        $row = BotFeedback::query()->first();
        $this->assertNotNull($row);
        $this->assertSame(BotFeedback::SOURCE_SUPPORT_AGENT, $row->source);
        $this->assertNull($row->score);
        Http::assertSent(fn (Request $sent): bool => $sent->url() === 'http://wa-web.test/send'
            && $sent['to'] === '963922222222'
            && str_contains((string) ($sent['text'] ?? ''), 'مساعد الدعم')
            && collect($sent['buttons'] ?? [])->contains(fn ($button): bool => str_starts_with((string) ($button['id'] ?? ''), 'botfb:')));
    }

    public function test_a_high_score_is_saved_without_a_reason(): void
    {
        Http::fake(['http://wa-web.test/*' => Http::response(['id' => 'wa-1'], 200)]);
        $client = Client::query()->firstOrFail();
        $survey = app(BotFeedbackSurvey::class);
        $survey->ask($client, 'wa:963922222222', BotFeedback::SOURCE_SUPPORT_AGENT);
        $row = BotFeedback::query()->firstOrFail();

        $this->assertTrue($survey->answer($client, 'wa:963922222222', "botfb:{$row->id}:5"));
        $row->refresh();
        $this->assertSame(5, $row->score);
        $this->assertNull($row->reason);
        Http::assertSent(fn (Request $sent): bool => str_contains((string) ($sent['text'] ?? ''), 'بيساعدنا'));
    }

    public function test_a_low_score_asks_why_and_alerts_management(): void
    {
        Http::fake(['http://wa-web.test/*' => Http::response(['id' => 'wa-1'], 200)]);
        $client = Client::query()->firstOrFail();
        $survey = app(BotFeedbackSurvey::class);
        $survey->ask($client, 'wa:963922222222', BotFeedback::SOURCE_SUPPORT_AGENT);
        $row = BotFeedback::query()->firstOrFail();

        $this->assertTrue($survey->answer($client, 'wa:963922222222', "botfb:{$row->id}:2"));
        Http::assertSent(fn (Request $sent): bool => $sent['to'] === '963922222222'
            && str_contains((string) ($sent['text'] ?? ''), 'ضايقك'));
        Http::assertSent(fn (Request $sent): bool => $sent['to'] === '963944000900'
            && str_contains((string) ($sent['text'] ?? ''), 'تقييم منخفض للبوت'));

        $this->assertTrue($survey->answer($client, 'wa:963922222222', "botfbwhy:{$row->id}:unclear"));
        $row->refresh();
        $this->assertSame('unclear', $row->reason);
    }

    public function test_admin_can_list_answered_feedback(): void
    {
        $client = Client::query()->firstOrFail();
        BotFeedback::query()->create([
            'client_id' => $client->id,
            'score' => 4,
            'source' => BotFeedback::SOURCE_SUPPORT_AGENT,
            'channel' => 'whatsapp',
            'asked_at' => now(),
            'answered_at' => now(),
        ]);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->getJson('/api/admin/bot-feedback')
            ->assertOk()
            ->assertJsonPath('data.0.score', 4)
            ->assertJsonPath('data.0.source', 'support_agent');
    }

    public function test_role_without_ability_cannot_list_feedback(): void
    {
        $role = Role::query()->create(['name' => 'بدون تقييم', 'abilities' => []]);
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id]));

        $this->getJson('/api/admin/bot-feedback')->assertForbidden();
    }

    private function send(string $text, string $id): void
    {
        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => '963922222222',
                'message_id' => $id,
                'text' => $text,
            ])
            ->assertOk();
    }
}
