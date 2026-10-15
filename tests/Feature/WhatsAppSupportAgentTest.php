<?php

namespace Tests\Feature;

use App\Actions\WhatsAppSupportAgent;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WhatsAppSupportAgentTest extends TestCase
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
            'services.whatsapp.support_numbers' => ['+963944000999'],
            'services.n8n.support_agent_url' => self::AGENT,
            'services.n8n.webhook_secret' => 'n8n-secret',
            'services.gemini.api_key' => '',
            'services.gemini.e2e_stub' => false,
        ]);
        Mail::fake();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-10-10 22:00:00', 'Asia/Damascus'));
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

    public function test_asking_for_a_person_moves_the_chat_to_the_agent(): void
    {
        $this->fakeAgent(['reply' => 'أهلين نور، شو اللي بقدر ساعدك فيه؟', 'handoff' => false, 'handoff_reason' => '']);

        $this->send('بدي احكي مع موظف', 'm1');

        Http::assertSent(fn (Request $sent): bool => $sent->url() === self::AGENT
            && $sent->hasHeader('X-N8N-Secret', 'n8n-secret')
            && $sent['message'] === 'بدي احكي مع موظف'
            && $sent['reason'] === 'The client asked for a person.'
            && str_contains((string) $sent['records'], 'Nour Co')
            && str_starts_with((string) $sent['session_id'], 'hoc-'));
        $this->assertSentToClient('مساعد الدعم');
        $this->assertSentToClient('أهلين نور، شو اللي بقدر ساعدك فيه؟');
        $this->assertTrue(app(WhatsAppSupportAgent::class)->active('963922222222'));

        $this->send('عندي سؤال عن تصميم اللوغو', 'm2');

        Http::assertSent(fn (Request $sent): bool => $sent->url() === self::AGENT && $sent['message'] === 'عندي سؤال عن تصميم اللوغو');
    }

    public function test_two_misses_in_a_row_bring_in_the_agent(): void
    {
        $this->fakeAgent(['reply' => 'خليني فهم عليك أكتر.', 'handoff' => false, 'handoff_reason' => '']);

        $this->send('شو قصة الملف الأزرق تبع امبارح', 'm1');
        Http::assertNotSent(fn (Request $sent): bool => $sent->url() === self::AGENT);

        $this->send('يعني الشي اللي حكينا عنه', 'm2');

        Http::assertSent(fn (Request $sent): bool => $sent->url() === self::AGENT
            && $sent['message'] === 'يعني الشي اللي حكينا عنه'
            && str_contains((string) $sent['reason'], 'did not understand twice'));
        $this->assertSentToClient('خليني فهم عليك أكتر.');
    }

    public function test_a_handoff_alerts_support_with_the_conversation_and_holds_the_bot(): void
    {
        $this->fakeAgent(['reply' => 'رح يتواصل معك موظف من الفريق بخصوص السعر.', 'handoff' => true, 'handoff_reason' => 'Asks for a discount.']);

        $this->send('بدي موظف، بدي خصم على الباقة', 'm1');

        $this->assertSentToClient('رح يتواصل معك موظف من الفريق بخصوص السعر.');
        Http::assertSent(fn (Request $sent): bool => $sent->url() === 'http://wa-web.test/send'
            && $sent['to'] === '963944000999'
            && str_contains((string) $sent['text'], 'Nour Co +963922222222')
            && str_contains((string) $sent['text'], 'Asks for a discount.')
            && str_contains((string) $sent['text'], 'العميل: بدي موظف، بدي خصم على الباقة'));
        $this->assertFalse(app(WhatsAppSupportAgent::class)->active('963922222222'));
        $this->assertTrue(Cache::has('hoc:wa-human:963922222222'));
    }

    public function test_an_agent_that_does_not_answer_hands_the_client_to_the_team(): void
    {
        Http::fake([
            self::AGENT => Http::response(['message' => 'Workflow failed'], 500),
            'http://wa-web.test/*' => Http::response(['id' => 'wa-1'], 200),
        ]);

        $this->send('بدي احكي مع موظف', 'm1');

        $this->assertSentToClient('حولتك لموظف من فريق الدعم');
        Http::assertSent(fn (Request $sent): bool => $sent->url() === 'http://wa-web.test/send'
            && $sent['to'] === '963944000999'
            && str_contains((string) $sent['text'], 'The support agent did not answer.'));
        $this->assertTrue(Cache::has('hoc:wa-human:963922222222'));
    }

    public function test_back_to_bot_leaves_the_agent(): void
    {
        $this->fakeAgent(['reply' => 'أكيد، تفضل.', 'handoff' => false, 'handoff_reason' => '']);
        $this->send('بدي احكي مع موظف', 'm1');

        $this->send('رجوع للبوت', 'm2');

        $this->assertFalse(app(WhatsAppSupportAgent::class)->active('963922222222'));
        $this->assertSentToClient('رجعت معك');
        Http::assertNotSent(fn (Request $sent): bool => $sent->url() === self::AGENT && $sent['message'] === 'رجوع للبوت');
    }

    public function test_without_the_agent_url_the_bot_keeps_its_old_escalation(): void
    {
        config(['services.n8n.support_agent_url' => '']);
        Http::fake(['http://wa-web.test/*' => Http::response(['id' => 'wa-1'], 200)]);

        $this->send('بدي احكي مع موظف', 'm1');

        Http::assertNotSent(fn (Request $sent): bool => str_contains($sent->url(), 'n8n.test'));
        $this->assertFalse(app(WhatsAppSupportAgent::class)->active('963922222222'));
    }

    public function test_the_person_phrases(): void
    {
        $this->assertTrue(WhatsAppSupportAgent::wantsHuman('بدي احكي مع موظف'));
        $this->assertTrue(WhatsAppSupportAgent::wantsHuman('حولني على خدمة العملاء'));
        $this->assertTrue(WhatsAppSupportAgent::wantsHuman('I want a real person'));
        $this->assertFalse(WhatsAppSupportAgent::wantsHuman('الموظف بعتلي الملف، شكراً'));
        $this->assertFalse(WhatsAppSupportAgent::wantsHuman('بدي باقة سوشيال ميديا'));
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function fakeAgent(array $output): void
    {
        Http::fake([
            self::AGENT => Http::response(['output' => $output], 200),
            'http://wa-web.test/*' => Http::response(['id' => 'wa-1'], 200),
        ]);
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

    private function assertSentToClient(string $fragment): void
    {
        Http::assertSent(fn (Request $sent): bool => $sent->url() === 'http://wa-web.test/send'
            && $sent['to'] === '963922222222'
            && str_contains((string) ($sent['text'] ?? ''), $fragment));
    }
}
