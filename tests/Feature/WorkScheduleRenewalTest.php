<?php

namespace Tests\Feature;

use App\Actions\BookPhotographySlot;
use App\Actions\ConfirmRequestPayment;
use App\Actions\ConfirmWorkPlan;
use App\Actions\DeclineRenewal;
use App\Actions\RenewSubscription;
use App\Actions\ResolveWorkPlan;
use App\Actions\SchedulePaymentReminders;
use App\Enums\ClickUpTaskType;
use App\Enums\EmployeeProfession;
use App\Enums\PaymentMethod;
use App\Enums\RequestStatus;
use App\Models\ClickUpTask;
use App\Models\Client;
use App\Models\Employee;
use App\Models\PaymentReminder;
use App\Models\PricingCategory;
use App\Models\PricingPackage;
use App\Models\PricingSubcategory;
use App\Models\ServiceRequest;
use App\Models\Subscription;
use App\Support\WorkCalendar;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkScheduleRenewalTest extends TestCase
{
    use RefreshDatabase;

    public function test_parallel_hours_skip_friday_and_use_the_longer_department(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-02 09:00:00', 'Asia/Damascus'));
        $calendar = app(WorkCalendar::class);

        $this->assertSame(16, $calendar->parallelHours([
            ['hours' => 16],
            ['hours' => 16],
        ]));
        $this->assertFalse($calendar->isWorkDay(now()));

        $due = $calendar->addWorkHours(now(), 16);
        $this->assertSame(CarbonInterface::SUNDAY, $due->dayOfWeek);
        $this->assertSame(17, (int) $due->format('G'));
        Carbon::setTestNow();
    }

    public function test_package_lines_are_not_rewritten_and_existing_tasks_stay(): void
    {
        Http::fake();
        config(['services.gemini.e2e_stub' => true]);
        Employee::factory()->create([
            'profession' => EmployeeProfession::Design,
            'name' => 'مصمم',
            'clickup_user_id' => '9',
        ]);
        $package = $this->package([
            ['department' => 'design', 'hours' => 16],
            ['department' => 'photography', 'hours' => 16],
        ]);
        $request = ServiceRequest::factory()->create([
            'pricing_package_id' => $package->id,
            'status' => RequestStatus::PaymentConfirmed,
            'paid_at' => now(),
            'billing_period' => 'monthly',
            'allows_renewal' => true,
            'subscription_ends_at' => now()->addMonth(),
        ]);

        $plan = app(ResolveWorkPlan::class)->handle($request);
        $this->assertSame('lines', $plan['source']);
        $this->assertSame(16, $plan['parallel_hours']);
        $this->assertSame(16, $plan['operations'][0]['hours']);

        $request->refresh();
        ClickUpTask::query()->create([
            'request_id' => $request->id,
            'task_type' => ClickUpTaskType::Design,
            'integration_key' => 'keep-'.$request->id,
            'status' => 'to do',
            'planned_hours' => 16,
        ]);
        $request->forceFill(['work_plan' => $plan])->save();
        $again = app(ResolveWorkPlan::class)->handle($request->fresh() ?? $request);
        $this->assertSame($plan['operations'][0]['hours'], $again['operations'][0]['hours']);
    }

    public function test_yearly_renewal_waits_for_payment_and_decline_shows_duration_only(): void
    {
        Http::fake();
        $client = Client::factory()->create(['telegram_user_id' => 'tg-year']);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::Completed,
            'paid_at' => now()->subMonths(11),
            'billing_period' => 'yearly',
            'allows_renewal' => true,
            'quotation_amount' => 1200,
            'amount_total' => 1200,
            'amount_paid' => 1200,
            'amount_remaining' => 0,
            'subscription_starts_at' => now()->subMonths(11),
            'subscription_ends_at' => now()->addDays(3),
        ]);
        Subscription::query()->create([
            'request_id' => $request->id,
            'billing_period' => 'yearly',
            'starts_at' => $request->subscription_starts_at,
            'ends_at' => $request->subscription_ends_at,
            'amount' => 1200,
            'amount_paid' => 1200,
            'status' => 'active',
        ]);

        $end = $request->subscription_ends_at->copy();
        $renewed = app(RenewSubscription::class)->handle($request->fresh() ?? $request);
        $this->assertTrue($renewed->subscription_ends_at->equalTo($end));
        $this->assertSame(1, $renewed->subscriptions()->where('status', 'pending_renewal')->count());
        app(RenewSubscription::class)->handle($renewed->fresh() ?? $renewed);
        $this->assertSame(1, $request->subscriptions()->where('status', 'pending_renewal')->count());

        $paid = app(ConfirmRequestPayment::class)->handle($renewed->fresh() ?? $renewed, PaymentMethod::Cash, 1200);
        $this->assertTrue($paid->subscription_ends_at->greaterThan($end->copy()->addMonths(11)));
        $this->assertTrue($paid->subscription_starts_at->equalTo($request->subscription_starts_at));
        $this->assertSame(RequestStatus::InProgress, $paid->status);

        $client = Client::factory()->create(['telegram_user_id' => 'tg-stop']);
        $ending = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::Completed,
            'billing_period' => 'monthly',
            'allows_renewal' => true,
            'subscription_ends_at' => now()->addDays(4),
            'number' => 'RQ-STOP',
        ]);
        app(DeclineRenewal::class)->handle($ending);
        $this->assertFalse($ending->fresh()->canRenew());
        $this->assertStringStartsWith('حتى ', (string) $ending->fresh()->durationLabel());

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->getJson('/api/bot/telegram/requests?telegram_user_id=tg-stop')
            ->assertOk()
            ->assertJsonPath('data.0.duration_label', $ending->fresh()->durationLabel())
            ->assertJsonPath('data.0.show_subscription', false)
            ->assertJsonPath('data.0.can_renew', false);

        Carbon::setTestNow(now()->addDays(5));
        $this->assertTrue($ending->fresh()->hiddenFromClient());
        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->getJson('/api/bot/telegram/requests?telegram_user_id=tg-stop')
            ->assertOk()
            ->assertJsonPath('data', []);
        Carbon::setTestNow();
    }

    public function test_renewal_reminder_starts_eight_days_before_the_end(): void
    {
        $request = ServiceRequest::factory()->create([
            'billing_period' => 'monthly',
            'allows_renewal' => true,
            'subscription_starts_at' => now()->subDays(10),
            'subscription_ends_at' => now()->addDays(20),
        ]);
        app(SchedulePaymentReminders::class)->handle($request);
        $reminder = PaymentReminder::query()->where('kind', PaymentReminder::KIND_RENEWAL)->firstOrFail();
        $this->assertEqualsWithDelta(12, now()->diffInDays($reminder->due_at), 1);
    }

    public function test_photography_same_day_requests_wait_for_one_staff_choice(): void
    {
        $request = ServiceRequest::factory()->create();
        $other = ServiceRequest::factory()->create();
        $book = app(BookPhotographySlot::class);
        $start = Carbon::parse('2026-10-03 09:00:00', 'Asia/Damascus');

        Carbon::setTestNow(Carbon::parse('2026-10-02 08:00:00', 'Asia/Damascus'));
        $first = $book->hold($request, $start->toIso8601String());
        $this->assertSame('pending_staff', $first->status);

        $clash = $book->hold($other, $start->toIso8601String());
        $this->assertSame('pending_staff', $clash->status);

        $third = ServiceRequest::factory()->create();
        $near = $book->hold($third, $start->copy()->addHours(2)->toIso8601String());
        $this->assertSame('pending_staff', $near->status);

        $book->approveSameTime($first);
        $this->assertSame('needs_client', $clash->fresh()->status);
        $this->assertSame('14:00', $near->fresh()->proposed_starts_at?->format('H:i'));
    }

    public function test_confirm_plan_runs_after_payment(): void
    {
        Http::fake();
        config(['services.gemini.e2e_stub' => false, 'services.gemini.api_key' => '']);
        Employee::factory()->create([
            'profession' => EmployeeProfession::Design,
            'clickup_user_id' => '4',
        ]);
        $package = $this->package([['department' => 'design', 'hours' => 8]]);
        $request = ServiceRequest::factory()->create([
            'pricing_package_id' => $package->id,
            'status' => RequestStatus::PaymentConfirmed,
            'paid_at' => now(),
            'description' => 'وصف طويل بما يكفي لتأكيد الخطة دون سؤال إضافي.',
        ]);

        $confirmed = app(ConfirmWorkPlan::class)->handle($request);
        $this->assertNotNull($confirmed->plan_confirmed_at);
        $this->assertNotEmpty($confirmed->work_plan['operations'] ?? []);
    }

    /**
     * @param  list<array{department: string, hours: int}>  $lines
     */
    private function package(array $lines): PricingPackage
    {
        $category = PricingCategory::query()->create([
            'slug' => 'cat-'.uniqid(),
            'name_en' => 'Cat',
            'name_ar' => 'قسم',
            'sort_order' => 1,
            'is_published' => true,
            'requires_full_payment' => true,
            'allows_renewal' => true,
        ]);
        $sub = PricingSubcategory::query()->create([
            'category_id' => $category->id,
            'slug' => 'sub-'.uniqid(),
            'name_en' => 'Sub',
            'name_ar' => 'فرعي',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        return PricingPackage::query()->create([
            'subcategory_id' => $sub->id,
            'slug' => 'pkg-'.uniqid(),
            'name_en' => 'Pkg',
            'name_ar' => 'باقة',
            'subtitle_en' => 'Sub',
            'subtitle_ar' => 'فرعي',
            'prices' => ['monthly' => 100, 'yearly' => 1200],
            'work_lines' => $lines,
            'sort_order' => 1,
            'is_published' => true,
        ]);
    }
}
