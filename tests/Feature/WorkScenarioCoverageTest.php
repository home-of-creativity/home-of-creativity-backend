<?php

namespace Tests\Feature;

use App\Actions\BookPhotographySlot;
use App\Actions\ConfirmRequestPayment;
use App\Actions\ConfirmWorkPlan;
use App\Actions\CreateCatalogRequest;
use App\Actions\DeclineRenewal;
use App\Actions\ExtendClientSchedule;
use App\Actions\RenewSubscription;
use App\Actions\RequestRevision;
use App\Actions\ResolveWorkPlan;
use App\Actions\SchedulePaymentReminders;
use App\Actions\SyncClickUpReview;
use App\Enums\ClickUpTaskType;
use App\Enums\EmployeeProfession;
use App\Enums\PaymentMethod;
use App\Enums\RequestStatus;
use App\Models\ClickUpTask;
use App\Models\Client;
use App\Models\DriveDelivery;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\PaymentReminder;
use App\Models\PhotographyBooking;
use App\Models\PricingCategory;
use App\Models\PricingPackage;
use App\Models\PricingSubcategory;
use App\Models\ServiceRequest;
use App\Models\Subscription;
use App\Support\BillingPeriod;
use App\Support\WorkCalendar;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkScenarioCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_calendar_skips_friday_holiday_and_uses_the_longer_parallel_line(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-02 09:00:00', 'Asia/Damascus'));
        $calendar = app(WorkCalendar::class);

        $this->assertFalse($calendar->isWorkDay(now()));
        $this->assertTrue($calendar->isWorkDay(now()->addDay()));
        $this->assertSame(8, $calendar->hoursPerDay());
        $this->assertSame(16, $calendar->parallelHours([
            ['hours' => 16],
            ['hours' => 8],
        ]));

        $calendar->saveHolidays(['2026-10-03']);
        $due = $calendar->addWorkHours(now(), 8);
        $this->assertSame(CarbonInterface::SUNDAY, $due->dayOfWeek);
        $this->assertSame('2026-10-04', $due->toDateString());
    }

    public function test_package_lines_draft_lines_and_cached_fallback_stay_separate(): void
    {
        Http::fake();
        config(['services.gemini.api_key' => '', 'services.gemini.e2e_stub' => true]);
        $designer = Employee::factory()->create([
            'profession' => EmployeeProfession::Design,
            'name' => 'مشغول',
            'clickup_user_id' => '20',
        ]);
        $free = Employee::factory()->create([
            'profession' => EmployeeProfession::Design,
            'name' => 'فاضي',
            'clickup_user_id' => '21',
        ]);
        ClickUpTask::query()->create([
            'request_id' => ServiceRequest::factory()->create()->id,
            'task_type' => ClickUpTaskType::Design,
            'employee_id' => $designer->id,
            'clickup_user_id' => '20',
            'integration_key' => 'busy-task',
            'status' => 'to do',
            'planned_hours' => 40,
        ]);

        $lined = $this->package([['department' => 'design', 'hours' => 16], ['department' => 'photography', 'hours' => 8]], 'lined');
        $empty = $this->package([], 'empty');
        $linedRequest = ServiceRequest::factory()->create([
            'pricing_package_id' => $lined->id,
            'status' => RequestStatus::PaymentConfirmed,
            'paid_at' => now(),
        ]);
        $plan = app(ResolveWorkPlan::class)->handle($linedRequest);
        $this->assertSame('lines', $plan['source']);
        $this->assertSame(16, $plan['parallel_hours']);
        $this->assertSame('فاضي', $plan['operations'][0]['employee_name']);
        $this->assertStringStartsWith('hoc:work-plan:v2:lines:', app(ResolveWorkPlan::class)->cacheKey($linedRequest));

        $manual = ServiceRequest::factory()->create([
            'title' => 'طلب يدوي',
            'description' => 'وصف واضح يكفي لبناء الخطة بدون سؤال إضافي من العميل.',
            'draft_work_lines' => [['department' => 'content', 'hours' => 10]],
            'status' => RequestStatus::PaymentConfirmed,
            'paid_at' => now(),
        ]);
        $manualPlan = app(ResolveWorkPlan::class)->handle($manual);
        $this->assertSame('content', $manualPlan['operations'][0]['department']);
        $this->assertSame(10, $manualPlan['operations'][0]['hours']);

        $legacy = ServiceRequest::factory()->create(['pricing_package_id' => $empty->id]);
        $this->assertStringStartsWith('hoc:work-plan:v1:pkg:', app(ResolveWorkPlan::class)->cacheKey($legacy));

        ClickUpTask::query()->create([
            'request_id' => $linedRequest->id,
            'task_type' => ClickUpTaskType::Design,
            'integration_key' => 'locked-'.$linedRequest->id,
            'status' => 'to do',
        ]);
        $linedRequest->forceFill(['work_plan' => $plan])->save();
        $again = app(ResolveWorkPlan::class)->handle($linedRequest->fresh(['clickupTasks']) ?? $linedRequest);
        $this->assertSame($plan['operations'], $again['operations']);
    }

    public function test_plan_is_confirmed_only_after_payment_and_a_second_confirm_does_not_rebuild(): void
    {
        Http::fake();
        config(['services.gemini.api_key' => '']);
        Employee::factory()->create(['profession' => EmployeeProfession::Design, 'clickup_user_id' => '7']);
        $package = $this->package([['department' => 'design', 'hours' => 8]], 'gate');
        $request = ServiceRequest::factory()->create([
            'pricing_package_id' => $package->id,
            'status' => RequestStatus::AwaitingPayment,
            'description' => 'قصير',
        ]);

        try {
            app(ConfirmWorkPlan::class)->handle($request);
            $this->fail('Plan confirm should wait for payment.');
        } catch (ValidationException $exception) {
            $this->assertSame('الخطة تُؤكد بعد موافقة الدفع.', $exception->errors()['plan'][0]);
        }

        $manual = ServiceRequest::factory()->create([
            'status' => RequestStatus::PaymentConfirmed,
            'paid_at' => now(),
            'description' => 'قصير',
        ]);
        try {
            app(ConfirmWorkPlan::class)->handle($manual);
            $this->fail('A short manual description should ask for one clarification.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('description', $exception->errors());
        }

        $request->forceFill(['status' => RequestStatus::PaymentConfirmed, 'paid_at' => now()])->save();
        $confirmed = app(ConfirmWorkPlan::class)->handle($request->fresh() ?? $request);
        $this->assertNotNull($confirmed->plan_confirmed_at);
        ClickUpTask::query()->create([
            'request_id' => $confirmed->id,
            'task_type' => ClickUpTaskType::Design,
            'integration_key' => 'confirmed-'.$confirmed->id,
            'status' => 'to do',
        ]);
        $stamp = $confirmed->plan_confirmed_at->copy();
        $second = app(ConfirmWorkPlan::class)->handle($confirmed->fresh(['clickupTasks']) ?? $confirmed);
        $this->assertTrue($second->plan_confirmed_at->equalTo($stamp));
    }

    public function test_each_billing_period_extends_only_after_payment(): void
    {
        Http::fake();
        foreach (['monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'yearly' => 12] as $period => $months) {
            $request = $this->renewable($period, now()->addDays(4));
            $end = $request->subscription_ends_at->copy();
            $quoted = app(RenewSubscription::class)->handle($request);
            $this->assertTrue($quoted->subscription_ends_at->equalTo($end));
            $this->assertSame('1200.00', $quoted->subscriptions()->where('status', 'pending_renewal')->first()?->amount);
            $this->assertSame(1, $quoted->invoices()->where('kind', 'renewal')->count());

            app(RenewSubscription::class)->handle($quoted->fresh() ?? $quoted);
            $this->assertSame(1, $request->subscriptions()->where('status', 'pending_renewal')->count());

            $paid = app(ConfirmRequestPayment::class)->handle($quoted->fresh() ?? $quoted, PaymentMethod::Cash, 1200);
            $this->assertEqualsWithDelta(
                $months * 30,
                $end->diffInDays($paid->subscription_ends_at),
                5,
            );
            $this->assertTrue($paid->subscription_starts_at->equalTo($request->subscription_starts_at));
        }
    }

    public function test_ended_subscription_starts_a_full_period_today_and_old_dates_are_not_applied_twice(): void
    {
        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-06 10:00:00', 'Asia/Damascus'));
        $request = $this->renewable('monthly', now()->subDays(2));
        $start = $request->subscription_starts_at->copy();
        $renewed = app(RenewSubscription::class)->handle($request);
        $paid = app(ConfirmRequestPayment::class)->handle($renewed->fresh() ?? $renewed, PaymentMethod::Cash, 1200);
        $this->assertTrue($paid->subscription_starts_at->equalTo($start));
        $this->assertTrue($paid->subscription_ends_at->greaterThan(now()->addDays(27)));

        $legacy = $this->renewable('monthly', now()->addDays(3));
        $pendingEnd = BillingPeriod::addPeriod($legacy->subscription_ends_at->copy(), 'monthly');
        $legacy->forceFill(['subscription_ends_at' => $pendingEnd])->save();
        Subscription::query()->create([
            'request_id' => $legacy->id,
            'billing_period' => 'monthly',
            'starts_at' => now(),
            'ends_at' => $pendingEnd,
            'amount' => 1200,
            'status' => 'pending_renewal',
        ]);
        $kept = app(ConfirmRequestPayment::class)->handle($legacy->fresh() ?? $legacy, PaymentMethod::Cash, 1200);
        $this->assertTrue($kept->subscription_ends_at->equalTo($pendingEnd));
    }

    public function test_renewal_window_one_time_requests_and_unpaid_invoice_void(): void
    {
        Http::fake();
        $early = $this->renewable('monthly', now()->addDays(12));
        $this->assertFalse($early->canRenew());
        try {
            app(RenewSubscription::class)->handle($early);
            $this->fail('Renewal is closed more than 8 days before the end.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $open = $this->renewable('monthly', now()->addDays(6));
        $open->forceFill(['status' => RequestStatus::InProgress])->save();
        $this->assertTrue($open->fresh()->canRenew());

        $once = ServiceRequest::factory()->create([
            'billing_period' => 'one_time',
            'allows_renewal' => true,
            'status' => RequestStatus::Completed,
            'subscription_ends_at' => now()->addDay(),
        ]);
        $this->assertFalse($once->canRenew());

        $ending = $this->renewable('monthly', now()->subHour());
        app(RenewSubscription::class)->handle($ending);
        $ending->forceFill(['subscription_ends_at' => now()->subMinute()])->save();
        $reminder = PaymentReminder::query()->create([
            'request_id' => $ending->id,
            'kind' => PaymentReminder::KIND_RENEWAL,
            'due_at' => now()->subMinute(),
            'send_count' => 0,
        ]);
        $this->artisan('ops:process-reminders')->assertSuccessful();
        $this->assertNotNull($reminder->fresh()?->completed_at);
        $this->assertSame('void', $ending->subscriptions()->where('status', 'void')->exists() ? 'void' : $ending->fresh()->subscriptions()->latest('id')->value('status'));
        $this->assertSame('void', $ending->invoices()->where('kind', 'renewal')->value('status'));
    }

    public function test_reminder_gaps_and_decline_hides_the_request_only_after_the_end(): void
    {
        $renewal = new PaymentReminder(['kind' => PaymentReminder::KIND_RENEWAL, 'due_at' => now()->subDay(), 'send_count' => 1, 'last_sent_at' => now()->subDays(2)]);
        $balance = new PaymentReminder(['kind' => PaymentReminder::KIND_REMAINING, 'due_at' => now()->subDay(), 'send_count' => 1, 'last_sent_at' => now()->subDays(2)]);
        $this->assertTrue($renewal->isDue());
        $this->assertFalse($balance->isDue());

        $request = $this->renewable('yearly', now()->addDays(40));
        app(SchedulePaymentReminders::class)->handle($request);
        $due = PaymentReminder::query()->where('request_id', $request->id)->where('kind', PaymentReminder::KIND_RENEWAL)->firstOrFail();
        $this->assertEqualsWithDelta(32, now()->diffInDays($due->due_at), 1);
        $this->assertTrue(
            PaymentReminder::query()->where('request_id', $request->id)->where('kind', PaymentReminder::KIND_REMAINING)->doesntExist()
        );

        $client = Client::factory()->create(['telegram_user_id' => 'tg-cover']);
        $visible = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::Completed,
            'billing_period' => 'monthly',
            'allows_renewal' => true,
            'subscription_ends_at' => now()->addDays(3),
        ]);
        ClickUpTask::query()->create([
            'request_id' => $visible->id,
            'task_type' => ClickUpTaskType::Design,
            'integration_key' => 'stay-'.$visible->id,
            'clickup_task_id' => 'stay-task',
        ]);
        app(DeclineRenewal::class)->handle($visible);
        $fresh = $visible->fresh();
        $this->assertFalse($fresh->canRenew());
        $this->assertFalse($fresh->hiddenFromClient());
        $this->assertNotNull($fresh->durationLabel());
        $this->assertSame(1, ClickUpTask::query()->where('request_id', $visible->id)->count());

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->getJson('/api/bot/telegram/requests?telegram_user_id=tg-cover')
            ->assertOk()
            ->assertJsonPath('data.0.show_subscription', false)
            ->assertJsonPath('data.0.can_renew', false);

        Carbon::setTestNow(now()->addDays(4));
        $this->assertTrue($visible->fresh()->hiddenFromClient());
        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->getJson('/api/bot/telegram/requests?telegram_user_id=tg-cover')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_catalog_does_not_duplicate_an_active_subscription_and_allows_a_new_one_after_it_ends(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        $client = Client::factory()->create([
            'telegram_user_id' => 'tg-cat',
            'name' => 'عميل',
            'phone' => '+963900001111',
            'company_name' => 'الشركة',
        ]);
        $package = $this->package([['department' => 'design', 'hours' => 4]], 'catalog');
        $other = $this->package([['department' => 'content', 'hours' => 4]], 'other');
        $active = ServiceRequest::factory()->for($client)->create([
            'pricing_package_id' => $package->id,
            'billing_period' => 'monthly',
            'allows_renewal' => true,
            'subscription_ends_at' => now()->addDays(10),
            'status' => RequestStatus::InProgress,
        ]);

        $same = app(CreateCatalogRequest::class)->handle($client, $package, 'monthly');
        $this->assertSame($active->id, $same->id);

        $different = app(CreateCatalogRequest::class)->handle($client, $other, 'monthly');
        $this->assertNotSame($active->id, $different->id);

        $active->forceFill(['subscription_ends_at' => now()->subDay()])->save();
        Subscription::query()->create([
            'request_id' => $active->id,
            'billing_period' => 'monthly',
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
            'amount' => 100,
            'status' => 'expiring',
            'renewal_declined' => true,
        ]);
        $fresh = app(CreateCatalogRequest::class)->handle($client, $package->fresh() ?? $package, 'monthly');
        $this->assertNotSame($active->id, $fresh->id);
    }

    public function test_the_client_path_cannot_open_a_photography_slot(): void
    {
        $client = Client::factory()->create(['telegram_user_id' => 'tg-no-photo']);
        $request = ServiceRequest::factory()->for($client)->create();

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$request->id}/photography-bookings", [
                'telegram_user_id' => 'tg-no-photo',
                'starts_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertUnprocessable();

        $this->assertSame(0, PhotographyBooking::query()->count());
    }

    public function test_photography_cannot_be_booked_before_a_week(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-07 08:00:00', 'Asia/Damascus'));
        $book = app(BookPhotographySlot::class);
        $request = ServiceRequest::factory()->create();

        try {
            $book->hold($request, Carbon::parse('2026-10-08 09:00:00', 'Asia/Damascus')->toIso8601String());
            $this->fail('A next-day photography slot must be refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('أسبوع', $exception->errors()['starts_at'][0]);
        }

        $held = $book->hold($request, Carbon::parse('2026-10-14 09:00:00', 'Asia/Damascus')->toIso8601String());
        $this->assertSame('pending_staff', $held->status);
        $this->assertSame('2026-10-14 09:00', $held->starts_at?->format('Y-m-d H:i'));
    }

    public function test_three_photography_bookings_at_the_same_time_keep_one_calendar_event(): void
    {
        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-09-26 08:00:00', 'Asia/Damascus'));
        $this->mock(\App\Services\GoogleCalendarClient::class, function ($mock): void {
            $mock->shouldReceive('createShoot')->once()->andReturn('evt-one');
            $mock->shouldReceive('deleteEvent')->twice();
        });
        $book = app(BookPhotographySlot::class);
        $start = Carbon::parse('2026-10-04 09:00:00', 'Asia/Damascus')->toIso8601String();
        $bookings = [];
        foreach ([1, 2, 3] as $ignored) {
            $bookings[] = $book->hold(ServiceRequest::factory()->create(), $start);
        }

        $first = $book->approveSameTime($bookings[0]);
        $this->assertSame('confirmed', $first->status);
        $this->assertSame('evt-one', $first->google_event_id);
        $this->assertSame('confirmed', $book->approveSameTime($first)->status);

        $second = $book->approveSameTime($bookings[1]->fresh() ?? $bookings[1]);
        $third = $book->approveSameTime($bookings[2]->fresh() ?? $bookings[2]);
        $this->assertSame('needs_client', $second->status);
        $this->assertSame('needs_client', $third->status);
        $this->assertSame('14:00', $second->proposed_starts_at?->format('H:i'));
        $this->assertSame('14:00', $third->proposed_starts_at?->format('H:i'));
        $this->assertSame(1, PhotographyBooking::query()->where('status', 'confirmed')->count());

        PhotographyBooking::query()->whereKey([$second->id, $third->id])->update([
            'status' => 'confirmed',
            'starts_at' => $first->starts_at,
            'ends_at' => $first->ends_at,
            'proposed_starts_at' => null,
        ]);
        PhotographyBooking::query()->whereKey($second->id)->update(['google_event_id' => 'evt-two']);
        PhotographyBooking::query()->whereKey($third->id)->update(['google_event_id' => 'evt-three']);

        $this->assertSame(2, $book->separateSameDayClashes());
        $this->assertSame(1, PhotographyBooking::query()->where('status', 'confirmed')->count());
        $this->assertSame('evt-one', $first->fresh()?->google_event_id);
        $this->assertNull($second->fresh()?->google_event_id);
        $this->assertSame('needs_client', $second->fresh()?->status);
        $this->assertSame('needs_client', $third->fresh()?->status);
    }

    public function test_one_edit_then_support_and_company_extension_needs_a_reason(): void
    {
        Http::fake();
        $request = ServiceRequest::factory()->create([
            'status' => RequestStatus::InProgress,
            'client_due_at' => Carbon::parse('2026-10-02 09:00:00', 'Asia/Damascus'),
        ]);
        DriveDelivery::query()->create([
            'request_id' => $request->id,
            'drive_file_id' => 'file-1',
            'name' => 'poster.png',
            'sent_at' => now(),
        ]);

        $updated = app(RequestRevision::class)->handle($request, 'غيّر اللون');
        $this->assertSame(1, $updated->edit_rounds);
        $this->assertSame(4, $updated->edit_estimate_hours);
        $this->assertSame(4, (int) ClickUpTask::query()->where('request_id', $request->id)->where('task_type', ClickUpTaskType::Revision)->value('planned_hours'));

        try {
            app(RequestRevision::class)->handle($updated->fresh() ?? $updated, 'مرة ثانية');
            $this->fail('A second edit should stop at support.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('+963 968 862 822', $exception->errors()['revision'][0]);
            $this->assertStringContainsString('info@hoc.agency', $exception->errors()['revision'][0]);
        }

        try {
            app(ExtendClientSchedule::class)->handle($request, 8, '   ');
            $this->fail('A company extension needs a reason.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $extended = app(ExtendClientSchedule::class)->handle($request->fresh() ?? $request, 8, 'ضغط موسم الأعياد');
        $this->assertSame('ضغط موسم الأعياد', $extended->schedule_extension_reason);
        $this->assertTrue($extended->client_due_at->greaterThan($request->client_due_at));
    }

    public function test_delivery_moves_clickup_tasks_to_review_and_approval_closes_them(): void
    {
        $request = ServiceRequest::factory()->create();
        $task = ClickUpTask::query()->create([
            'request_id' => $request->id,
            'task_type' => ClickUpTaskType::Design,
            'clickup_task_id' => 'cu-review',
            'integration_key' => 'review-'.$request->id,
            'status' => 'to do',
        ]);

        config(['services.clickup.lists.revision' => 'rev-list']);
        app(SyncClickUpReview::class)->markDelivered($request);
        $this->assertSame('review', $task->fresh()->status);
        $this->assertSame('rev-list', $task->fresh()->clickup_list_id);
        app(SyncClickUpReview::class)->markApproved($request->fresh() ?? $request);
        $this->assertSame('complete', $task->fresh()->status);
    }

    /**
     * @param  list<array{department: string, hours: int}>  $lines
     */
    private function package(array $lines, string $slug): PricingPackage
    {
        $category = PricingCategory::query()->create([
            'slug' => 'cat-'.$slug,
            'name_en' => 'Cat',
            'name_ar' => 'قسم',
            'sort_order' => 1,
            'is_published' => true,
            'requires_full_payment' => true,
            'allows_renewal' => true,
        ]);
        $sub = PricingSubcategory::query()->create([
            'category_id' => $category->id,
            'slug' => 'sub-'.$slug,
            'name_en' => 'Sub',
            'name_ar' => 'فرعي',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        return PricingPackage::query()->create([
            'subcategory_id' => $sub->id,
            'slug' => 'pkg-'.$slug,
            'name_en' => 'Pkg',
            'name_ar' => 'باقة',
            'subtitle_en' => 'Sub',
            'subtitle_ar' => 'فرعي',
            'prices' => ['monthly' => 100, 'quarterly' => 300, 'semiannual' => 600, 'yearly' => 1200],
            'work_lines' => $lines === [] ? null : $lines,
            'sort_order' => 1,
            'is_published' => true,
        ]);
    }

    private function renewable(string $period, Carbon $ends): ServiceRequest
    {
        $request = ServiceRequest::factory()->create([
            'status' => RequestStatus::Completed,
            'paid_at' => now()->subMonth(),
            'billing_period' => $period,
            'allows_renewal' => true,
            'quotation_amount' => 1200,
            'amount_total' => 1200,
            'amount_paid' => 1200,
            'amount_remaining' => 0,
            'subscription_starts_at' => now()->subYear(),
            'subscription_ends_at' => $ends,
        ]);
        Subscription::query()->create([
            'request_id' => $request->id,
            'billing_period' => $period,
            'starts_at' => $request->subscription_starts_at,
            'ends_at' => $ends,
            'amount' => 1200,
            'amount_paid' => 1200,
            'status' => 'active',
        ]);

        return $request;
    }
}
