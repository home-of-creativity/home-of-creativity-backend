<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\WhatsAppOutboxMessage;
use App\Services\WhatsAppOutbox;
use App\Services\WhatsAppWebClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WhatsAppOutboxTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '963944000222';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.transport' => 'web',
            'services.whatsapp.enabled' => false,
            'services.whatsapp.web_url' => 'http://wa-web.test',
            'services.whatsapp.web_secret' => 'web-secret',
        ]);
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake([
            'http://wa-web.test/*' => Http::response(['id' => 'wa-web-1'], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-10 22:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_reads_how_many_messages_the_client_wants_again(): void
    {
        $this->assertSame(3, WhatsAppOutbox::requestedCount('ارجع آخر 3 رسائل'));
        $this->assertSame(3, WhatsAppOutbox::requestedCount('رجعلي اخر ٣ رسائل'));
        $this->assertSame(5, WhatsAppOutbox::requestedCount('أعد إرسال آخر 5 رسائل'));
        $this->assertSame(2, WhatsAppOutbox::requestedCount('ابعتلي آخر رسالتين'));
        $this->assertSame(1, WhatsAppOutbox::requestedCount('عيد آخر رسالة'));
        $this->assertSame(3, WhatsAppOutbox::requestedCount('ارجع اخر الرسائل'));
        $this->assertSame(2, WhatsAppOutbox::requestedCount('resend the last 2 messages'));
        $this->assertNull(WhatsAppOutbox::requestedCount('بدي ألغي الطلب'));
        $this->assertNull(WhatsAppOutbox::requestedCount('الرسالة تاخرت كتير ابعتها'));
        $this->assertNull(WhatsAppOutbox::requestedCount('ابعت عرض السعر'));
    }

    public function test_sent_messages_are_kept_and_only_the_last_ten_stay(): void
    {
        $client = new WhatsAppWebClient;
        for ($i = 1; $i <= 12; $i++) {
            $client->sendText('+'.self::PHONE, 'رسالة '.$i);
        }
        $client->sendList(self::PHONE, 'اختر', [['text' => 'نعم', 'callback_data' => 'yes']]);
        $file = tempnam(sys_get_temp_dir(), 'hoc');
        file_put_contents($file, '%PDF-1.4 test');
        $client->sendDocument(self::PHONE, $file, 'عرض السعر', 'quote.pdf');
        @unlink($file);

        $kept = WhatsAppOutboxMessage::query()->where('recipient', self::PHONE)->orderBy('id')->get();
        $this->assertCount(WhatsAppOutbox::KEEP, $kept);
        $this->assertSame('رسالة 5', $kept->first()->body);
        $this->assertSame('choice', $kept[8]->kind);
        $this->assertSame([['text' => 'نعم', 'callback_data' => 'yes']], $kept[8]->buttons);
        $this->assertSame('document', $kept[9]->kind);
        $this->assertSame('quote.pdf', $kept[9]->filename);
        Storage::disk('local')->assertExists($kept[9]->file_path);
    }

    public function test_the_client_gets_the_last_three_messages_again_at_most(): void
    {
        $this->clientRow();
        $client = new WhatsAppWebClient;
        $client->sendText(self::PHONE, 'الأولى');
        $client->sendText(self::PHONE, 'الثانية');
        $client->sendList(self::PHONE, 'الثالثة', [['text' => 'نعم', 'callback_data' => 'yes']]);
        $file = tempnam(sys_get_temp_dir(), 'hoc');
        file_put_contents($file, '%PDF-1.4 test');
        $client->sendDocument(self::PHONE, $file, 'الرابعة', 'quote.pdf');
        @unlink($file);
        $before = WhatsAppOutboxMessage::query()->count();
        Http::fake([
            'http://wa-web.test/*' => Http::response(['id' => 'wa-web-2'], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);

        $this->inbound('resend-1', 'ارجع آخر 5 رسائل');

        $sent = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $request): bool => str_starts_with($request->url(), 'http://wa-web.test/send'))
            ->map(fn (Request $request): array => [
                'kind' => (string) data_get($request->data(), 'kind'),
                'text' => (string) (data_get($request->data(), 'text') ?? data_get($request->data(), 'caption')),
            ])
            ->values()
            ->all();

        $tail = array_slice($sent, -4);
        $this->assertStringContainsString('3 رسائل بحد أقصى', $tail[0]['text']);
        $this->assertSame(['text', 'الثانية'], [$tail[1]['kind'], $tail[1]['text']]);
        $this->assertSame(['list', 'الثالثة'], [$tail[2]['kind'], $tail[2]['text']]);
        $this->assertSame(['document', 'الرابعة'], [$tail[3]['kind'], $tail[3]['text']]);
        $this->assertSame($before, WhatsAppOutboxMessage::query()->count());
    }

    public function test_with_no_history_the_client_is_told_so(): void
    {
        $this->clientRow();

        $this->inbound('resend-2', 'عيد آخر رسالة');

        Http::assertSent(fn (Request $request): bool => str_contains((string) data_get($request->data(), 'text'), 'ما في رسائل سابقة'));
        $this->assertSame(0, WhatsAppOutboxMessage::query()->count());
    }

    private function clientRow(): void
    {
        Client::factory()->create([
            'name' => 'نور',
            'phone' => '+'.self::PHONE,
            'company_name' => 'النور',
            'telegram_user_id' => 'wa:'.self::PHONE,
            'odoo_lead_id' => 9,
        ]);
    }

    private function inbound(string $id, string $text): void
    {
        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => self::PHONE,
                'message_id' => $id,
                'text' => $text,
            ])
            ->assertOk();
    }
}
