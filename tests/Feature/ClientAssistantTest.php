<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Mail\ClientMessageEscalated;
use App\Models\Client;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ClientAssistantTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $prompts = [];

    private string $reply = '{"action":"answer","ref":"","answer":"","reason":""}';

    private int $geminiStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.transport' => 'web',
            'services.whatsapp.enabled' => false,
            'services.whatsapp.web_url' => 'http://wa-web.test',
            'services.whatsapp.web_secret' => 'web-secret',
            'services.gemini.e2e_stub' => false,
            'services.gemini.api_key' => 'test-key',
            'services.gemini.vertex_project' => '',
            'services.client_assistant.escalation_email' => 'developer@hoc.agency',
        ]);
        Mail::fake();
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'wa-web.test')) {
                return Http::response(['id' => 'wa-web-1'], 200);
            }
            if (! str_contains($request->url(), 'generativelanguage.googleapis.com')) {
                return Http::response([], 200);
            }
            $this->prompts[] = (string) data_get($request->data(), 'contents.0.parts.0.text');

            return Http::response([
                'candidates' => [['content' => ['parts' => [['text' => $this->reply]]]]],
            ], $this->geminiStatus);
        });
        Carbon::setTestNow(Carbon::parse('2026-10-10 22:00:00', 'Asia/Damascus'));
        Cache::put('site-guide-v3', 'Home of Creativity brief', now()->addHour());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_question_about_the_clients_own_request_is_answered_from_their_records(): void
    {
        $client = $this->client('963955555551', 'Nour');
        $mine = ServiceRequest::factory()->create([
            'client_id' => $client->id,
            'title' => 'Brand identity',
            'status' => RequestStatus::InProgress,
            'quotation_amount' => 400,
            'amount_paid' => 200,
            'amount_remaining' => 200,
        ]);
        $other = $this->client('963955555552', 'Other Person');
        ServiceRequest::factory()->create(['client_id' => $other->id, 'title' => 'Secret project']);
        $this->reply = json_encode([
            'action' => 'answer',
            'ref' => '',
            'answer' => 'طلبك قيد التنفيذ، ودفعت 200 USD وباقي 200 USD.',
            'reason' => '',
        ], JSON_UNESCAPED_UNICODE);

        $this->send('963955555551', 'قديش باقي علي لطلب الهوية؟');

        $this->assertCount(1, $this->prompts);
        $prompt = $this->prompts[0];
        $this->assertStringContainsString($mine->number, $prompt);
        $this->assertStringContainsString('remaining 200 USD', $prompt);
        $this->assertStringContainsString('Home of Creativity brief', $prompt);
        $this->assertStringNotContainsString('Secret project', $prompt);
        $this->assertStringNotContainsString('Other Person', $prompt);
        $this->assertSentTo('963955555551', 'باقي 200 USD');
        Mail::assertNothingSent();
    }

    public function test_the_records_show_photography_sessions_and_the_waiting_offer(): void
    {
        $client = $this->client('963955555557', 'Maya');
        $request = ServiceRequest::factory()->create([
            'client_id' => $client->id,
            'status' => RequestStatus::InProgress,
            'paid_at' => now(),
            'photography_sessions' => 2,
            'photography_sessions_used' => 1,
        ]);
        \App\Models\PhotographyBooking::query()->create([
            'request_id' => $request->id,
            'starts_at' => '2026-10-19 09:00:00',
            'ends_at' => '2026-10-19 12:00:00',
            'proposed_starts_at' => '2026-10-19 14:00:00',
            'status' => 'needs_client',
        ]);

        $records = app(\App\Actions\ClientAssistant::class)->records($client->fresh());

        $this->assertStringContainsString('2 shoot session(s) this period, 1 used', $records);
        $this->assertStringContainsString('2026-10-19 14:00', $records);
        $this->assertStringContainsString('answer the shoot time the team offered', $records);
    }

    public function test_a_message_the_assistant_does_not_understand_is_emailed_once(): void
    {
        $this->client('963955555553', 'Lina');
        $this->reply = json_encode([
            'action' => 'escalate',
            'ref' => '',
            'answer' => '',
            'reason' => 'The client asks to move the logo files to another company.',
        ]);

        $this->send('963955555553', 'بدي تنقلولي ملفات اللوغو لشركة تانية عندي', 'm1');
        $this->send('963955555553', 'بدي تنقلولي ملفات اللوغو لشركة تانية عندي', 'm2');

        Mail::assertSent(ClientMessageEscalated::class, 1);
        Mail::assertSent(ClientMessageEscalated::class, function (ClientMessageEscalated $mail): bool {
            return $mail->hasTo('developer@hoc.agency')
                && $mail->messageText === 'بدي تنقلولي ملفات اللوغو لشركة تانية عندي'
                && str_contains($mail->reason, 'logo files')
                && $mail->chatUrl === 'https://wa.me/963955555553';
        });
        $this->assertSentTo('963955555553', 'وصلتنا رسالتك');
    }

    public function test_when_gemini_is_down_the_message_still_reaches_the_developer(): void
    {
        $this->client('963955555554', 'Sami');
        $this->geminiStatus = 500;

        $this->send('963955555554', 'عندي مشكلة بالفاتورة يلي وصلتني');

        Mail::assertSent(ClientMessageEscalated::class, fn (ClientMessageEscalated $mail): bool => str_contains($mail->reason, 'Gemini did not answer'));
        $this->assertSentTo('963955555554', 'وصلتنا رسالتك');
    }

    public function test_the_assistant_opens_the_request_it_names(): void
    {
        $client = $this->client('963955555555', 'Rami');
        $request = ServiceRequest::factory()->create([
            'client_id' => $client->id,
            'title' => 'Menu design',
            'status' => RequestStatus::InProgress,
        ]);
        $this->reply = json_encode([
            'action' => 'open_request',
            'ref' => '#'.(int) substr($request->number, -6),
            'answer' => '',
            'reason' => '',
        ]);

        $this->send('963955555555', 'فرجيني تفاصيل طلب تصميم المنيو');

        $this->assertSentTo('963955555555', 'Menu design');
        Mail::assertNothingSent();
    }

    public function test_a_greeting_gets_the_menu_without_asking_gemini(): void
    {
        $this->client('963955555556', 'Hala');

        $this->send('963955555556', 'السلام عليكم');

        $this->assertSame([], $this->prompts);
        $this->assertSentTo('963955555556', 'كيف فيني ساعدك');
    }

    public function test_the_telegram_client_bot_uses_the_same_assistant(): void
    {
        config(['services.telegram.bot_secret' => 'tg-secret']);
        $client = Client::query()->create([
            'name' => 'Omar',
            'phone' => '+963966666661',
            'company_name' => 'Omar Co',
            'telegram_user_id' => '777001',
            'locale' => 'ar',
        ]);
        $request = ServiceRequest::factory()->create(['client_id' => $client->id, 'status' => RequestStatus::InProgress]);
        $this->reply = json_encode([
            'action' => 'open_request',
            'ref' => '#'.(int) substr($request->number, -6),
            'answer' => 'هاد طلبك.',
            'reason' => '',
        ], JSON_UNESCAPED_UNICODE);

        $this->withHeaders(['X-Webhook-Secret' => 'tg-secret'])
            ->postJson('/api/bot/telegram/assistant', [
                'telegram_user_id' => '777001',
                'message' => 'وين صار طلبي؟',
                'history' => [['role' => 'user', 'text' => 'مرحبا']],
            ])
            ->assertOk()
            ->assertJsonPath('data.action', 'open_request')
            ->assertJsonPath('data.request_number', $request->number);
        $this->assertStringContainsString($request->number, $this->prompts[0]);

        $this->reply = '{"action":"escalate","ref":"","answer":"","reason":"Asks for a refund."}';
        $this->withHeaders(['X-Webhook-Secret' => 'tg-secret'])
            ->postJson('/api/bot/telegram/assistant', [
                'telegram_user_id' => '777001',
                'message' => 'بدي استرجع المبلغ',
            ])
            ->assertOk()
            ->assertJsonPath('data.action', 'escalate');
        Mail::assertSent(ClientMessageEscalated::class, fn (ClientMessageEscalated $mail): bool => $mail->channel === 'تيليجرام');
    }

    private function client(string $phone, string $name): Client
    {
        return Client::query()->create([
            'name' => $name,
            'phone' => '+'.$phone,
            'company_name' => $name.' Co',
            'telegram_user_id' => 'wa:'.$phone,
            'locale' => 'ar',
        ]);
    }

    private function send(string $phone, string $text, string $id = 'm'): void
    {
        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => $phone,
                'message_id' => $id.'-'.$phone,
                'text' => $text,
            ])
            ->assertOk();
    }

    private function assertSentTo(string $phone, string $needle): void
    {
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://wa-web.test/send'
            && $request['to'] === $phone
            && str_contains((string) data_get($request->data(), 'text'), $needle));
    }
}
