<?php

namespace Tests\Feature;

use App\Actions\SatisfactionSurvey;
use App\Actions\WeeklyFinanceSummary;
use App\Enums\EmployeeProfession;
use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\Employee;
use App\Models\OdooInvoice;
use App\Models\OpsExpense;
use App\Models\PhotographyBooking;
use App\Models\RequestRating;
use App\Models\RequestStatusHistory;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientAutomationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.transport' => 'web',
            'services.whatsapp.web_url' => 'http://wa-web.test',
            'services.whatsapp.web_secret' => 'web-secret',
            'services.whatsapp.management_numbers' => ['+963 944 000 900'],
            'services.telegram.bot_token' => '',
            'services.telegram.admin_bot_token' => '',
            'services.telegram.staff_bot_token' => '',
        ]);
        Http::fake(['http://wa-web.test/*' => Http::response(['id' => 'wa-1'], 200)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_client_and_photographer_get_a_whatsapp_reminder_two_hours_before(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 11:30:00', 'Asia/Damascus'));
        $photographer = Employee::factory()->create(['phone' => '+963 944 000 111', 'profession' => EmployeeProfession::Media]);
        $booking = $this->booking('2026-10-20 13:00:00', $photographer, confirmedAt: now()->subDays(2));

        $this->artisan('ops:photography-hours-before')->assertSuccessful();

        $this->assertNotNull($booking->fresh()->hour_reminded_at);
        Http::assertSent(fn (Request $sent): bool => $sent['to'] === '963944000222'
            && str_contains((string) $sent['text'], 'تذكير: موعد التصوير اليوم'));
        Http::assertSent(fn (Request $sent): bool => $sent['to'] === '963944000111'
            && str_contains((string) $sent['text'], 'جلسة تصوير بعد ساعتين')
            && str_contains((string) $sent['text'], 'لا داعي للرد'));

        Http::fake(['http://wa-web.test/*' => Http::response(['id' => 'wa-2'], 200)]);
        $this->artisan('ops:photography-hours-before')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_a_shoot_agreed_inside_the_window_is_not_reminded_again(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 11:30:00', 'Asia/Damascus'));
        $photographer = Employee::factory()->create(['phone' => '+963 944 000 111', 'profession' => EmployeeProfession::Media]);
        $booking = $this->booking('2026-10-20 13:00:00', $photographer, confirmedAt: now()->subMinutes(30));
        $later = $this->booking('2026-10-20 18:00:00', $photographer, confirmedAt: now()->subDays(2));

        $this->artisan('ops:photography-hours-before')->assertSuccessful();

        $this->assertNotNull($booking->fresh()->hour_reminded_at);
        $this->assertNull($later->fresh()->hour_reminded_at);
        Http::assertNothingSent();
    }

    public function test_a_whatsapp_client_is_asked_to_rate_a_completed_project_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00', 'Asia/Damascus'));
        $request = $this->completedRequest('wa:963944000222', hoursAgo: 3);

        $this->artisan('ops:satisfaction-survey')->assertSuccessful();

        $rating = RequestRating::query()->where('request_id', $request->id)->firstOrFail();
        $this->assertSame('whatsapp', $rating->channel);
        $this->assertNotNull($rating->asked_at);
        Http::assertSent(fn (Request $sent): bool => $sent['to'] === '963944000222'
            && $sent['kind'] === 'list'
            && count($sent['buttons']) === 5
            && $sent['buttons'][0]['id'] === "rate:{$rating->id}:5");

        Http::fake(['http://wa-web.test/*' => Http::response(['id' => 'wa-2'], 200)]);
        $this->artisan('ops:satisfaction-survey')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_the_survey_waits_for_the_delivery_and_skips_telegram_clients(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00', 'Asia/Damascus'));
        $fresh = $this->completedRequest('wa:963944000222', hoursAgo: 1);
        $telegram = $this->completedRequest('5550001', hoursAgo: 3, phone: '+963944000333');

        $this->artisan('ops:satisfaction-survey')->assertSuccessful();

        $this->assertFalse(RequestRating::query()->where('request_id', $fresh->id)->exists());
        $this->assertNull(RequestRating::query()->where('request_id', $telegram->id)->first()?->asked_at);
        Http::assertNothingSent();
    }

    public function test_a_low_score_asks_why_and_alerts_management(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00:00', 'Asia/Damascus'));
        $request = $this->completedRequest('wa:963944000222', hoursAgo: 3);
        $rating = RequestRating::query()->create(['request_id' => $request->id, 'client_id' => $request->client_id, 'asked_at' => now()]);
        $survey = app(SatisfactionSurvey::class);

        $this->assertTrue($survey->answer($request->client, 'wa:963944000222', "rate:{$rating->id}:2"));
        $this->assertSame(2, $rating->fresh()->score);
        Http::assertSent(fn (Request $sent): bool => $sent['to'] === '963944000222'
            && $sent['kind'] === 'list'
            && $sent['buttons'][0]['id'] === "ratewhy:{$rating->id}:time");
        Http::assertSent(fn (Request $sent): bool => $sent['to'] === '963944000900'
            && str_contains((string) $sent['text'], 'تقييم منخفض: 2 من 5'));

        $this->assertTrue($survey->answer($request->client, 'wa:963944000222', "ratewhy:{$rating->id}:quality"));
        $this->assertSame('quality', $rating->fresh()->reason);
        Http::assertSent(fn (Request $sent): bool => $sent['to'] === '963944000900'
            && str_contains((string) $sent['text'], 'جودة الشغل'));
    }

    public function test_a_high_score_thanks_the_client_without_alerting_management(): void
    {
        $request = $this->completedRequest('wa:963944000222', hoursAgo: 3);
        $rating = RequestRating::query()->create(['request_id' => $request->id, 'client_id' => $request->client_id, 'asked_at' => now()]);

        $this->assertTrue(app(SatisfactionSurvey::class)->answer($request->client, 'wa:963944000222', "rate:{$rating->id}:5"));

        $this->assertSame(5, $rating->fresh()->score);
        Http::assertSent(fn (Request $sent): bool => $sent['to'] === '963944000222' && str_contains((string) $sent['text'], 'شكراً كتير'));
        Http::assertNotSent(fn (Request $sent): bool => $sent['to'] === '963944000900');
    }

    public function test_another_client_cannot_answer_someone_elses_survey(): void
    {
        $request = $this->completedRequest('wa:963944000222', hoursAgo: 3);
        $rating = RequestRating::query()->create(['request_id' => $request->id, 'client_id' => $request->client_id, 'asked_at' => now()]);
        $stranger = Client::factory()->create(['telegram_user_id' => 'wa:963944000777']);

        $this->assertFalse(app(SatisfactionSurvey::class)->answer($stranger, 'wa:963944000777', "rate:{$rating->id}:1"));
        $this->assertNull($rating->fresh()->score);
    }

    public function test_management_gets_the_weekly_finance_summary_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-18 09:00:00', 'Asia/Damascus'));
        $this->invoice(1, '2026-10-12', 1000, 400, 'partial', '2026-11-30');
        $this->invoice(2, '2026-10-05', 500, 0, 'paid', '2026-10-20');
        $this->invoice(3, '2026-09-01', 300, 300, 'not_paid', '2026-09-15', 'متجر الورد');
        OpsExpense::query()->create(['amount' => 150, 'category' => 'ads', 'spent_at' => '2026-10-14']);

        $this->artisan('ops:finance-weekly')->assertSuccessful();

        Http::assertSent(function (Request $sent): bool {
            $text = (string) ($sent['text'] ?? '');

            return $sent['to'] === '963944000900'
                && str_contains($text, 'من 11/10 إلى 17/10')
                && str_contains($text, 'الفواتير الصادرة: 1,000.00 USD (الأسبوع اللي قبله 500.00 USD)')
                && str_contains($text, 'المحصّل منها: 600.00 USD')
                && str_contains($text, 'المصاريف: 150.00 USD')
                && str_contains($text, 'الصافي: 450.00 USD')
                && str_contains($text, '- متجر الورد: 300.00 USD');
        });

        Http::fake(['http://wa-web.test/*' => Http::response(['id' => 'wa-2'], 200)]);
        $this->artisan('ops:finance-weekly')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_the_week_runs_sunday_to_saturday(): void
    {
        [$start, $end] = app(WeeklyFinanceSummary::class)->lastWeek(Carbon::parse('2026-10-21 10:00:00', 'Asia/Damascus'));

        $this->assertSame('2026-10-11', $start->toDateString());
        $this->assertSame('2026-10-17', $end->toDateString());
    }

    private function booking(string $startsAt, Employee $photographer, Carbon $confirmedAt): PhotographyBooking
    {
        $client = Client::query()->where('telegram_user_id', 'wa:963944000222')->first()
            ?? Client::factory()->create(['telegram_user_id' => 'wa:963944000222', 'phone' => '+963944000222']);
        $request = ServiceRequest::factory()->photography()->create(['client_id' => $client->id]);

        return PhotographyBooking::query()->create([
            'request_id' => $request->id,
            'employee_id' => $photographer->id,
            'starts_at' => $startsAt,
            'ends_at' => Carbon::parse($startsAt)->addHours(3)->format('Y-m-d H:i:s'),
            'status' => PhotographyBooking::CONFIRMED,
            'confirmed_at' => $confirmedAt,
        ]);
    }

    private function completedRequest(string $chatId, int $hoursAgo, string $phone = '+963944000222'): ServiceRequest
    {
        $client = Client::factory()->create(['telegram_user_id' => $chatId, 'phone' => $phone]);
        $request = ServiceRequest::factory()->create(['client_id' => $client->id, 'status' => RequestStatus::Completed]);
        $history = RequestStatusHistory::query()->create([
            'request_id' => $request->id,
            'from_status' => RequestStatus::InProgress->value,
            'to_status' => RequestStatus::Completed->value,
            'actor' => 'admin',
        ]);
        $history->forceFill(['created_at' => now()->subHours($hoursAgo)])->save();

        return $request->fresh(['client']);
    }

    private function invoice(int $id, string $date, float $total, float $residual, string $paymentState, string $due, string $partner = 'شركة النور'): void
    {
        OdooInvoice::query()->create([
            'odoo_id' => $id,
            'name' => 'INV/2026/'.$id,
            'state' => 'posted',
            'payment_state' => $paymentState,
            'partner_name' => $partner,
            'currency' => 'USD',
            'amount_total' => $total,
            'amount_residual' => $residual,
            'invoice_date' => $date,
            'invoice_date_due' => $due,
        ]);
    }
}
