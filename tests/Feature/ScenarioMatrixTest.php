<?php

namespace Tests\Feature;

use App\Actions\ConfirmRequestPayment;
use App\Actions\IssueInvoice;
use App\Actions\RequestRevision;
use App\Actions\ResolveWorkPlan;
use App\Enums\PaymentMethod;
use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\DriveDelivery;
use App\Models\ServiceRequest;
use App\Support\ClientChannelGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScenarioMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.transport' => 'web',
            'services.whatsapp.enabled' => false,
            'services.whatsapp.web_url' => 'http://wa-web.test',
            'services.whatsapp.web_secret' => 'web-secret',
            'services.telegram.bot_token' => 'test-token',
            'services.n8n.webhook_secret' => 'change-me',
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-10 22:00:00', 'Asia/Damascus'));
        Http::preventStrayRequests();
        Http::fake([
            'http://wa-web.test/*' => Http::response(['id' => 'wa-1'], 200),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"answer":"الجواب على hoc.agency"}']]],
                ]],
            ], 200),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_data_cannot_change_after_the_quotation_is_sent(): void
    {
        $owner = Client::factory()->create([
            'name' => 'Nour',
            'phone' => '+963944444444',
            'company_name' => 'Nour Co',
            'telegram_user_id' => 'wa:963944444444',
        ]);
        $request = ServiceRequest::factory()->for($owner)->create([
            'title' => 'العنوان الأصلي',
            'description' => 'وصف أصلي يكفي للاختبار هنا.',
            'status' => RequestStatus::QuotationSent,
        ]);
        $other = Client::factory()->create(['telegram_user_id' => 'tg-other']);

        $started = microtime(true);
        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => '963944444444',
                'message_id' => 'edit-locked',
                'button_id' => 'edit:'.$request->number,
            ])
            ->assertOk();
        $this->assertLessThan(2, microtime(true) - $started);

        $this->assertSame('العنوان الأصلي', $request->fresh()->title);
        Http::assertSent(fn (Request $sent): bool => str_contains((string) data_get($sent->data(), 'text'), 'ما عاد'));

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->patchJson('/api/bot/telegram/requests/'.$request->id, [
                'telegram_user_id' => 'wa:963944444444',
                'title' => 'عنوان مسروق',
            ])
            ->assertStatus(422);
        $this->assertSame('العنوان الأصلي', $request->fresh()->title);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->patchJson('/api/bot/telegram/requests/'.$request->id, [
                'telegram_user_id' => 'tg-other',
                'title' => 'عنوان الغير',
            ])
            ->assertForbidden();
        $this->assertSame('العنوان الأصلي', $request->fresh()->title);
        $this->assertSame(0, $other->requests()->count());

        $ready = ServiceRequest::factory()->for($owner)->create([
            'status' => RequestStatus::ReadyForReview,
            'title' => 'تسليم',
        ]);
        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => '963944444444',
                'message_id' => 'show-ready',
                'button_id' => 'open:'.$ready->number,
            ])
            ->assertOk();
        Http::assertSent(fn (Request $sent): bool => collect(data_get($sent->data(), 'buttons', []))->contains('title', 'طلب تعديل'));
    }

    public function test_support_is_the_phone_and_a_down_channel_still_replies_in_arabic(): void
    {
        $client = Client::factory()->create([
            'name' => 'Nour',
            'phone' => '+963955555555',
            'company_name' => 'Nour Co',
            'telegram_user_id' => 'wa:963955555555',
        ]);
        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => '963955555555',
                'message_id' => 'help-1',
                'text' => 'الدعم',
            ])
            ->assertOk();

        $help = $this->sentText();
        $this->assertStringContainsString('0947823488', $help);
        $this->assertStringNotContainsString('عطلة', $help);
        $this->assertStringNotContainsString('الجمعة', $help);
        $this->assertStringNotContainsString('تصوير', $help);

        ClientChannelGate::setWhatsAppEnabled(false);
        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => '963955555555',
                'message_id' => 'paused-1',
                'text' => 'مرحبا',
            ])
            ->assertOk();
        $this->assertStringContainsString(ClientChannelGate::WHATSAPP_PAUSED_MESSAGE, $this->sentText());
        $this->assertSame(0, $client->requests()->count());
    }

    public function test_gemini_failure_and_a_payment_sentence_do_not_stall_or_confirm_payment(): void
    {
        config(['services.gemini.e2e_stub' => false, 'services.gemini.api_key' => '']);
        $client = Client::factory()->create([
            'name' => 'Nour',
            'phone' => '+963966666666',
            'company_name' => 'Nour Co',
            'telegram_user_id' => 'wa:963966666666',
        ]);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::AwaitingPayment,
            'quotation_amount' => 80,
            'amount_total' => 80,
        ]);

        $this->mock(\App\Services\GeminiService::class, function ($mock): void {
            $mock->shouldReceive('answerSiteQuestion')->andThrow(new \RuntimeException('gemini down'));
            $mock->shouldReceive('assistClient')->andReturnNull();
            $mock->shouldReceive('recommendPackage')->andReturnNull();
        });

        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => '963966666666',
                'message_id' => 'ask-down',
                'text' => 'وين مكتبكم بالحمراء؟',
            ])
            ->assertOk();
        $this->assertStringContainsString('0947823488', $this->sentText());

        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', [
                'phone' => '963966666666',
                'message_id' => 'pay-words',
                'text' => 'لقد حولت المبلغ كاملا الآن',
            ])
            ->assertOk();
        $this->assertSame(RequestStatus::AwaitingPayment, $request->fresh()->status);
    }

    public function test_odoo_invoice_failure_still_saves_the_payment_in_arabic(): void
    {
        $this->mock(IssueInvoice::class, function ($mock): void {
            $mock->shouldReceive('handle')->once()->andThrow(new \RuntimeException('odoo down'));
        });
        $client = Client::factory()->create(['telegram_user_id' => '555001']);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::AwaitingPayment,
            'quotation_amount' => 100,
            'amount_total' => 100,
            'amount_paid' => 0,
            'amount_remaining' => 100,
        ]);

        $updated = app(ConfirmRequestPayment::class)->handle($request, PaymentMethod::Cash, 100);

        $this->assertSame(RequestStatus::PaymentConfirmed, $updated->status);
        $this->assertGreaterThan(0, (float) $updated->amount_paid);
        Http::assertSent(fn (Request $sent): bool => str_contains((string) data_get($sent->data(), 'text'), 'تم بنجاح'));
    }

    public function test_n8n_cannot_create_a_quotation_and_a_revision_notice_stays_clean(): void
    {
        $request = ServiceRequest::factory()->create([
            'title' => 'قبل العرض',
            'description' => 'وصف ثابت يكفي للاختبار هنا.',
            'status' => RequestStatus::Submitted,
        ]);

        $this->withHeaders(['X-N8N-Secret' => 'change-me'])
            ->postJson('/api/webhooks/n8n', [
                'event' => 'QUOTATION_READY',
                'request_number' => $request->number,
                'payload' => ['amount' => 999],
            ])
            ->assertStatus(422);

        $fresh = $request->fresh();
        $this->assertSame('قبل العرض', $fresh->title);
        $this->assertSame(RequestStatus::Submitted, $fresh->status);

        $live = ServiceRequest::factory()->create([
            'status' => RequestStatus::InProgress,
            'title' => 'تنفيذ',
        ]);
        DriveDelivery::query()->create([
            'request_id' => $live->id,
            'drive_file_id' => 'file-matrix',
            'name' => 'poster.png',
            'sent_at' => now(),
        ]);
        config([
            'services.telegram.admin_bot_token' => 'admin-token',
            'services.telegram.admin_telegram_ids' => ['9'],
        ]);
        app(RequestRevision::class)->handle($live, 'غيّر اللون');
        $notice = $this->sentText();
        $this->assertStringContainsString('طلب تعديل', $notice);
        $this->assertStringNotContainsString('عطلة', $notice);
        $this->assertStringNotContainsString('تصوير', $notice);
    }

    public function test_the_same_site_question_and_package_plan_do_not_call_gemini_twice(): void
    {
        config([
            'services.gemini.api_key' => 'test-key',
            'services.gemini.e2e_stub' => false,
            'services.gemini.vertex_project' => '',
        ]);
        Cache::put('site-guide-v3', 'Home of Creativity brief hoc.agency', now()->addHour());

        $this->postJson('/api/site/ask', ['question' => 'وين المكتب؟', 'locale' => 'ar'])->assertOk();
        $this->postJson('/api/site/ask', ['question' => 'وين المكتب؟', 'locale' => 'ar'])->assertOk();

        $gemini = 0;
        Http::recorded(function (Request $request) use (&$gemini): void {
            if (str_contains($request->url(), 'generativelanguage.googleapis.com')) {
                $gemini++;
            }
        });
        $this->assertSame(1, $gemini);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"operations":[{"department":"design","brief":"تنفيذ","hours":8,"priority":3}]}']]],
                ]],
            ], 200),
        ]);
        $first = ServiceRequest::factory()->create([
            'pricing_package_id' => null,
            'title' => 'باقة واحدة',
            'description' => 'وصف الباقة يكفي للاختبار.',
            'draft_work_lines' => null,
        ]);
        $second = ServiceRequest::factory()->create([
            'pricing_package_id' => null,
            'title' => 'باقة واحدة',
            'description' => 'وصف الباقة يكفي للاختبار.',
            'draft_work_lines' => null,
        ]);
        app(ResolveWorkPlan::class)->handle($first);
        app(ResolveWorkPlan::class)->handle($second);
        $plans = 0;
        Http::recorded(function (Request $request) use (&$plans): void {
            if (str_contains($request->url(), 'generativelanguage.googleapis.com') && str_contains((string) $request->body(), 'operations')) {
                $plans++;
            }
        });
        $this->assertSame(1, $plans);
        $this->assertSame('cache', $second->fresh()->work_plan['source'] ?? null);
    }

    private function sentText(): string
    {
        $texts = [];
        Http::recorded(function (Request $request) use (&$texts): void {
            $text = data_get($request->data(), 'text');
            if (is_string($text) && $text !== '') {
                $texts[] = $text;
            }
        });

        return implode("\n", $texts);
    }
}
