<?php

namespace Tests\Feature;

use App\Actions\ConfirmRequestPayment;
use App\Actions\SendQuotation;
use App\Enums\EmployeeProfession;
use App\Enums\PaymentMethod;
use App\Enums\RequestStatus;
use App\Jobs\ClassifyWithGeminiJob;
use App\Models\Client;
use App\Models\Employee;
use App\Models\OpsFollowUp;
use App\Models\RequestFile;
use App\Models\Revision;
use App\Models\ServiceRequest;
use App\Models\SupportMessage;
use App\Support\ResolveServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BotSlaAutomationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'client-token',
            'services.telegram.staff_bot_token' => 'staff-token',
            'services.telegram.strict' => false,
            'services.telegram.sla.sales_digest_hour' => 23,
            'services.gemini.e2e_stub' => true,
        ]);
        Employee::factory()->sales()->create(['telegram_user_id' => 'sales-sla']);
    }

    public function test_submitted_request_without_quote_alerts_sales_once(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $client = Client::factory()->create([
            'telegram_user_id' => '10001',
            'company_name' => 'شركة الاختبار',
        ]);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::Submitted,
            'title' => 'هوية يدوية',
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
        ]);

        $this->artisan('ops:process-bot-sla')->assertSuccessful();
        $this->artisan('ops:process-bot-sla')->assertSuccessful();

        Http::assertSent(function (Request $http) use ($request): bool {
            $text = $this->telegramText($http);

            return str_contains($http->url(), 'botstaff-token/sendMessage')
                && str_contains($text, 'طلب بلا عرض سعر')
                && str_contains($text, $request->title)
                && str_contains($text, 'tg://user?id=10001');
        });
        $this->assertSame(1, OpsFollowUp::query()->where('kind', OpsFollowUp::KIND_QUOTE_WAITING)->count());
    }

    public function test_awaiting_payment_without_receipt_reminds_client_then_sales(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $client = Client::factory()->create([
            'telegram_user_id' => '10002',
            'company_name' => 'شركة',
        ]);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::AwaitingPayment,
            'title' => 'دفعة أولى',
            'quotation_amount' => 200,
            'amount_total' => 200,
            'updated_at' => now()->subHours(13),
        ]);

        $this->artisan('ops:process-bot-sla')->assertSuccessful();

        Http::assertSent(function (Request $http): bool {
            return str_contains($http->url(), 'botclient-token/')
                && str_contains($this->telegramText($http), 'تذكير برفع وصل التحويل');
        });
        $this->assertSame(1, OpsFollowUp::query()->where('kind', OpsFollowUp::KIND_RECEIPT_WAITING)->count());

        $request->forceFill(['updated_at' => now()->subHours(25)])->save();
        config(['services.telegram.sla.receipt_waiting_sales_hours' => 24]);

        $this->artisan('ops:process-bot-sla')->assertSuccessful();

        Http::assertSent(function (Request $http): bool {
            return str_contains($http->url(), 'botstaff-token/sendMessage')
                && str_contains($this->telegramText($http), 'وافق ولم يرفع وصلاً');
        });
        $this->assertSame(1, OpsFollowUp::query()->where('kind', OpsFollowUp::KIND_RECEIPT_WAITING_SALES)->count());
    }

    public function test_uploaded_receipt_without_confirm_resends_sales_card(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $request = ServiceRequest::factory()->create([
            'status' => RequestStatus::AwaitingPayment,
            'title' => 'وصل معلّق',
            'quotation_amount' => 150,
            'amount_total' => 150,
        ]);
        $receipt = RequestFile::query()->create([
            'request_id' => $request->id,
            'kind' => 'payment_receipt',
            'original_name' => 'receipt.jpg',
            'path' => 'receipts/pending.jpg',
        ]);
        RequestFile::query()->whereKey($receipt->id)->update([
            'created_at' => now()->subHours(4),
            'updated_at' => now()->subHours(4),
        ]);

        $this->artisan('ops:process-bot-sla')->assertSuccessful();
        $this->artisan('ops:process-bot-sla')->assertSuccessful();

        Http::assertSent(function (Request $http): bool {
            $text = $this->telegramText($http);
            $payload = json_encode($http->data(), JSON_UNESCAPED_UNICODE) ?: $http->body();

            return str_contains($http->url(), 'botstaff-token/')
                && str_contains($text, 'وصل مرفوع بلا تأكيد')
                && str_contains($payload, 'payok:');
        });
        $this->assertSame(1, OpsFollowUp::query()->where('kind', OpsFollowUp::KIND_RECEIPT_UNCONFIRMED)->count());
    }

    public function test_payment_confirm_sends_execution_started_line(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
            '*' => Http::response(['ok' => true], 200),
        ]);
        Bus::fake([ClassifyWithGeminiJob::class]);

        $client = Client::factory()->create(['telegram_user_id' => '10003', 'company_name' => 'شركة']);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::AwaitingPayment,
            'quotation_amount' => 80,
            'amount_total' => 80,
            'work_plan' => [
                'operations' => [
                    ['department' => 'design', 'hours' => 6, 'employee_name' => 'مصمم'],
                ],
            ],
        ]);

        app(ConfirmRequestPayment::class)->handle($request, PaymentMethod::Receipt, 80);

        Http::assertSent(function (Request $http): bool {
            return str_contains($http->url(), 'botclient-token/sendMessage')
                && str_contains($this->telegramText($http), 'بدأ التنفيذ — تصميم — المتوقع: خلال 6 ساعة');
        });
        $this->assertTrue(
            OpsFollowUp::query()->where('kind', OpsFollowUp::KIND_EXECUTION_STARTED)->where('dedupe_key', 'request:'.$request->id)->exists()
        );
    }

    public function test_stale_revision_and_support_alert_staff(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        Employee::factory()->create([
            'profession' => EmployeeProfession::Design,
            'telegram_user_id' => 'design-sla',
        ]);

        $client = Client::factory()->create(['telegram_user_id' => '10004', 'company_name' => 'شركة']);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::RevisionRequested,
            'title' => 'تعديل الهوية',
            'work_type' => 'design',
        ]);
        $revision = Revision::query()->create([
            'request_id' => $request->id,
            'comments' => 'غيّر اللون',
            'status' => 'open',
        ]);
        $revision->forceFill([
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ])->save();
        $support = SupportMessage::query()->create([
            'client_id' => $client->id,
            'request_id' => $request->id,
            'message' => 'وين التسليم؟',
        ]);
        $support->forceFill([
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ])->save();

        $this->artisan('ops:process-bot-sla')->assertSuccessful();

        Http::assertSent(fn (Request $http): bool => str_contains($this->telegramText($http), 'تعديل بلا رد منذ ساعة')
            && str_contains($this->telegramText($http), 'غيّر اللون'));
        Http::assertSent(fn (Request $http): bool => str_contains($this->telegramText($http), 'رسالة دعم بلا رد منذ ساعة')
            && str_contains($this->telegramText($http), 'وين التسليم؟'));
    }

    public function test_incomplete_profile_gets_one_client_reminder(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        Client::factory()->create([
            'telegram_user_id' => '10005',
            'name' => 'سامر',
            'phone' => null,
            'company_name' => null,
            'created_at' => now()->subDay()->subHour(),
            'updated_at' => now()->subDay()->subHour(),
        ]);

        $this->artisan('ops:process-bot-sla')->assertSuccessful();
        $this->artisan('ops:process-bot-sla')->assertSuccessful();

        Http::assertSent(fn (Request $http): bool => str_contains($http->url(), 'botclient-token/sendMessage')
            && str_contains($this->telegramText($http), 'أكمل ملفك في البوت'));
        $this->assertSame(1, OpsFollowUp::query()->where('kind', OpsFollowUp::KIND_PROFILE_INCOMPLETE)->count());
    }

    public function test_morning_sales_digest_sends_once_per_day(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        config(['services.telegram.sla.sales_digest_hour' => 9]);
        $this->travelTo(Carbon::parse('2026-09-20 09:05:00', 'Asia/Damascus'));

        ServiceRequest::factory()->create(['status' => RequestStatus::Submitted]);
        ServiceRequest::factory()->create(['status' => RequestStatus::AwaitingPayment]);
        ServiceRequest::factory()->create(['status' => RequestStatus::RevisionRequested]);

        $this->artisan('ops:process-bot-sla')->assertSuccessful();
        $this->artisan('ops:process-bot-sla')->assertSuccessful();

        Http::assertSent(function (Request $http): bool {
            $text = $this->telegramText($http);

            return str_contains($text, 'هضم المبيعات — 2026-09-20')
                && str_contains($text, 'بانتظار عرض: 1')
                && str_contains($text, 'بانتظار وصل: 1')
                && str_contains($text, 'تعديلات مفتوحة: 1');
        });
        $this->assertSame(1, OpsFollowUp::query()->where('kind', OpsFollowUp::KIND_SALES_DIGEST)->count());
    }

    public function test_quotation_telegram_failure_alerts_sales_once(): void
    {
        Http::fake([
            'https://api.telegram.org/botclient-token/*' => Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked'], 403),
            'https://api.telegram.org/botstaff-token/*' => Http::response(['ok' => true], 200),
        ]);

        $client = Client::factory()->create(['telegram_user_id' => '10006', 'company_name' => 'شركة']);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::Submitted,
            'title' => 'عرض فاشل',
        ]);

        app(SendQuotation::class)->handle($request, 99, 'تجربة');
        app(SendQuotation::class)->handle($request->fresh() ?? $request, 110, 'إعادة', skipStatusTransition: true);

        Http::assertSent(function (Request $http): bool {
            $text = $this->telegramText($http);

            return str_contains($http->url(), 'botstaff-token/sendMessage')
                && str_contains($text, 'فشل إيصال تلغرام')
                && str_contains($text, 'عرض السعر');
        });
        $this->assertSame(1, OpsFollowUp::query()->where('kind', OpsFollowUp::KIND_TELEGRAM_FAIL)->count());
        $this->assertSame(RequestStatus::QuotationSent, $request->fresh()?->status);
    }

    public function test_display_number_used_on_quote_waiting_card(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $request = ServiceRequest::factory()->create([
            'status' => RequestStatus::Submitted,
            'created_at' => now()->subHours(5),
            'updated_at' => now()->subHours(5),
        ]);

        $this->artisan('ops:process-bot-sla')->assertSuccessful();

        $ref = ResolveServiceRequest::displayNumber($request);
        Http::assertSent(fn (Request $http): bool => str_contains($this->telegramText($http), '#'.$ref));
    }

    private function telegramText(Request $http): string
    {
        $data = $http->data();
        foreach (['text', 'caption'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                return $data[$key];
            }
        }

        return $http->body();
    }
}
