<?php

namespace Tests\Feature;

use App\Actions\ConfirmWorkPlan;
use App\Actions\CreateCatalogRequest;
use App\Actions\RenewSubscription;
use App\Actions\ResolveWorkPlan;
use App\Actions\SyncClickUpReview;
use App\Enums\ClickUpTaskType;
use App\Enums\EmployeeProfession;
use App\Enums\EmployeeStatus;
use App\Enums\RequestStatus;
use App\Models\ClickUpTask;
use App\Models\Client;
use App\Models\DriveDelivery;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\PaymentReminder;
use App\Models\ServiceRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Support\WorkCalendar;
use App\Support\WorkLines;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkScenarioEdgesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dashboard_calendar_rejects_bad_input_and_a_holiday_does_not_message_clients(): void
    {
        Http::fake();
        config(['services.telegram.bot_token' => 'client-token']);
        Client::factory()->create(['telegram_user_id' => 'tg-holiday']);

        $this->getJson('/api/admin/ops-settings/work-calendar')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/admin/ops-settings/work-calendar')->assertForbidden();

        Sanctum::actingAs($this->admin());
        $this->putJson('/api/admin/ops-settings/work-calendar', [
            'hours_per_day' => 0,
            'holidays' => ['2026-10-03'],
        ])->assertUnprocessable();
        $this->putJson('/api/admin/ops-settings/work-calendar', [
            'hours_per_day' => 8,
            'holidays' => ['03-10-2026'],
        ])->assertUnprocessable();

        $this->putJson('/api/admin/ops-settings/work-calendar', [
            'hours_per_day' => 6,
            'holidays' => ['2026-10-03'],
        ])->assertOk()
            ->assertJsonPath('data.hours_per_day', 6)
            ->assertJsonPath('data.weekend', 'friday')
            ->assertJsonPath('data.holidays.0', '2026-10-03');

        Http::assertNothingSent();
        $this->assertSame(6, app(WorkCalendar::class)->hoursPerDay());
    }

    public function test_dashboard_lines_plan_and_extension_follow_the_gates(): void
    {
        $this->fakeOutbound();
        config(['services.telegram.bot_token' => 'client-token', 'services.gemini.api_key' => '']);
        Sanctum::actingAs($this->admin());
        Employee::factory()->create(['profession' => EmployeeProfession::Design, 'clickup_user_id' => '9']);
        $client = Client::factory()->create(['telegram_user_id' => 'tg-plan']);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::AwaitingPayment,
            'description' => 'وصف واضح يكفي لتأكيد الخطة دون سؤال إضافي.',
        ]);

        $this->putJson("/api/admin/requests/{$request->id}/draft-work-lines", [
            'lines' => [['department' => 'sales', 'hours' => 4]],
        ])->assertUnprocessable();
        $this->putJson("/api/admin/requests/{$request->id}/draft-work-lines", [
            'lines' => [['department' => 'design', 'hours' => 0]],
        ])->assertUnprocessable();
        $this->putJson("/api/admin/requests/{$request->id}/draft-work-lines", [
            'lines' => [['department' => 'design', 'hours' => 16]],
        ])->assertOk();
        $this->assertSame(16, $request->fresh()->draft_work_lines[0]['hours']);

        $this->postJson("/api/admin/requests/{$request->id}/confirm-plan")->assertUnprocessable();
        $this->postJson("/api/admin/requests/{$request->id}/extend-schedule", [
            'hours' => 8,
        ])->assertUnprocessable();

        $request->forceFill([
            'status' => RequestStatus::PaymentConfirmed,
            'paid_at' => now(),
            'client_due_at' => Carbon::parse('2026-10-01 09:00:00', 'Asia/Damascus'),
        ])->save();

        $this->postJson("/api/admin/requests/{$request->id}/confirm-plan")->assertOk();
        $this->assertNotNull($request->fresh()->plan_confirmed_at);
        $this->assertSame(16, $request->fresh()->work_plan['operations'][0]['hours']);

        $due = $request->fresh()->client_due_at;
        $this->postJson("/api/admin/requests/{$request->id}/extend-schedule", [
            'hours' => 8,
            'reason' => 'ظرف من الشركة',
        ])->assertOk();
        $this->assertTrue($request->fresh()->client_due_at->greaterThan($due));
        $this->assertStringContainsString('ظرف من الشركة', $this->sentTexts());
    }

    public function test_channels_split_whatsapp_telegram_and_email_and_overtime_does_not_move_the_client(): void
    {
        $this->fakeOutbound();
        config([
            'services.telegram.bot_token' => 'client-token',
            'services.whatsapp.enabled' => true,
            'services.whatsapp.token' => 'wa-token',
            'services.whatsapp.phone_number_id' => '123',
        ]);
        Sanctum::actingAs($this->admin());

        $whatsapp = Client::factory()->create(['telegram_user_id' => 'wa:963900000111']);
        $request = ServiceRequest::factory()->for($whatsapp)->create([
            'status' => RequestStatus::InProgress,
            'client_due_at' => now(),
        ]);
        $this->postJson("/api/admin/requests/{$request->number}/extend-schedule", [
            'hours' => 4,
            'reason' => 'تأخير مطبعة',
        ])->assertOk();
        $sent = Http::recorded();
        $this->assertTrue($sent->contains(fn (array $pair): bool => str_contains($pair[0]->url(), 'graph.facebook.com')));
        $this->assertStringContainsString('تأخير مطبعة', $this->sentTexts());
        $this->assertFalse($sent->contains(fn (array $pair): bool => str_contains($pair[0]->url(), 'api.telegram.org')));

        $mailClient = Client::factory()->create(['telegram_user_id' => null, 'email' => 'client@example.com']);
        $mailRequest = ServiceRequest::factory()->for($mailClient)->create([
            'status' => RequestStatus::InProgress,
            'client_due_at' => now(),
        ]);
        $this->postJson("/api/admin/requests/{$mailRequest->number}/extend-schedule", [
            'hours' => 4,
            'reason' => 'بريد فقط',
        ])->assertOk();
        $messages = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('بريد فقط', quoted_printable_decode($messages[0]->toString()));

        $stamp = $request->fresh()->client_due_at->copy();
        ClickUpTask::query()->create([
            'request_id' => $request->id,
            'task_type' => ClickUpTaskType::Design,
            'integration_key' => 'overtime-'.$request->id,
            'status' => 'in progress',
            'planned_hours' => 40,
        ]);
        $this->assertTrue($request->fresh()->client_due_at->equalTo($stamp));
    }

    public function test_payment_message_hides_hours_on_subscriptions_and_uses_the_longer_line_once(): void
    {
        $this->fakeOutbound();
        Bus::fake();
        config(['services.telegram.bot_token' => 'client-token']);
        Sanctum::actingAs($this->admin());
        $plan = [
            'operations' => [
                ['department' => 'design', 'hours' => 16, 'brief' => 'تصميم'],
                ['department' => 'photography', 'hours' => 8, 'brief' => 'تصوير'],
            ],
        ];

        $subscription = ServiceRequest::factory()->for(Client::factory()->create(['telegram_user_id' => 'tg-sub']))->create([
            'status' => RequestStatus::AwaitingPayment,
            'billing_period' => 'monthly',
            'google_drive_folder_id' => 'folder-test',
            'work_plan' => $plan,
        ]);
        $this->postJson("/api/admin/requests/{$subscription->id}/confirm-payment", [
            'payment_method' => 'cash',
            'amount' => 100,
        ])->assertOk();

        $once = ServiceRequest::factory()->for(Client::factory()->create(['telegram_user_id' => 'tg-once']))->create([
            'status' => RequestStatus::AwaitingPayment,
            'billing_period' => 'one_time',
            'google_drive_folder_id' => 'folder-test',
            'work_plan' => $plan,
        ]);
        $this->postJson("/api/admin/requests/{$once->id}/confirm-payment", [
            'payment_method' => 'cash',
            'amount' => 100,
        ])->assertOk();

        $bodies = $this->sentTexts();
        $this->assertStringContainsString('بدأ التنفيذ', $bodies);
        $this->assertStringNotContainsString('خلال 24', $bodies);
        $this->assertStringContainsString('خلال 16 ساعة', $bodies);
    }

    public function test_staff_bot_confirms_the_plan_and_picks_one_photography_slot(): void
    {
        Http::fake();
        config(['services.gemini.api_key' => '']);
        Carbon::setTestNow(Carbon::parse('2026-10-03 08:00:00', 'Asia/Damascus'));
        Employee::factory()->create([
            'profession' => EmployeeProfession::Design,
            'telegram_user_id' => 'staff-pending',
            'status' => EmployeeStatus::Pending,
            'clickup_user_id' => '1',
        ]);
        Employee::factory()->create([
            'profession' => EmployeeProfession::Sales,
            'telegram_user_id' => 'staff-ok',
            'clickup_user_id' => '2',
        ]);
        $request = ServiceRequest::factory()->create([
            'status' => RequestStatus::PaymentConfirmed,
            'paid_at' => now(),
            'draft_work_lines' => [['department' => 'design', 'hours' => 8]],
            'description' => 'وصف واضح يكفي لتأكيد الخطة دون سؤال إضافي.',
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->postJson('/api/bot/staff/confirm-plan', [
                'telegram_user_id' => 'staff-pending',
                'request_number' => $request->number,
            ])->assertNotFound();

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->postJson('/api/bot/staff/confirm-plan', [
                'telegram_user_id' => 'staff-ok',
                'request_number' => $request->number,
            ])->assertOk();
        $this->assertNotNull($request->fresh()->plan_confirmed_at);

        $owner = Client::factory()->create(['telegram_user_id' => 'tg-photo']);
        $first = ServiceRequest::factory()->for($owner)->create();
        $start = Carbon::parse('2026-10-04 09:00:00', 'Asia/Damascus')->toIso8601String();

        $this->clientBot()->postJson("/api/bot/telegram/requests/{$first->id}/photography-bookings", [
            'telegram_user_id' => 'tg-photo',
            'starts_at' => $start,
        ])->assertUnprocessable();
        $this->assertSame(0, \App\Models\PhotographyBooking::query()->count());
    }

    public function test_the_client_cannot_open_a_photography_slot_and_support_is_the_phone_only(): void
    {
        Http::fake();
        $owner = Client::factory()->create(['telegram_user_id' => 'tg-gap']);
        $request = ServiceRequest::factory()->for($owner)->create();

        $this->clientBot()->postJson("/api/bot/telegram/requests/{$request->id}/photography-bookings", [
            'telegram_user_id' => 'tg-gap',
            'starts_at' => Carbon::parse('2026-10-04 09:00:00', 'Asia/Damascus')->toIso8601String(),
        ])->assertUnprocessable();
        $this->clientBot()->postJson("/api/bot/telegram/requests/{$request->id}/photography-decision", [
            'telegram_user_id' => 'tg-gap',
            'accept' => true,
        ])->assertUnprocessable();
        $this->assertSame(0, \App\Models\PhotographyBooking::query()->count());

        $this->artisan('ops:photography-day-before')->assertSuccessful();

        $brief = $this->clientBot()->getJson('/api/bot/telegram/support-brief')->assertOk();
        $this->assertSame('0947823488', $brief->json('data.phone'));
        $body = json_encode($brief->json('data'), JSON_UNESCAPED_UNICODE);
        $this->assertIsString($body);
        $this->assertStringNotContainsString('عطلة', $body);
        $this->assertStringNotContainsString('الجمعة', $body);
        $this->assertStringNotContainsString('تصوير', $body);
    }

    public function test_assignment_ignores_finished_hours_and_a_replan_does_not_replace_the_clickup_row(): void
    {
        Http::fake();
        config(['services.gemini.api_key' => '', 'services.gemini.e2e_stub' => true]);
        $named = Employee::factory()->create([
            'profession' => EmployeeProfession::Design,
            'name' => 'معرّف',
            'clickup_user_id' => '30',
        ]);
        Employee::factory()->create([
            'profession' => EmployeeProfession::Design,
            'name' => 'بلا معرف',
            'clickup_user_id' => null,
        ]);
        $busier = Employee::factory()->create([
            'profession' => EmployeeProfession::Design,
            'name' => 'أثقل',
            'clickup_user_id' => '31',
        ]);
        ClickUpTask::query()->create([
            'request_id' => ServiceRequest::factory()->create()->id,
            'task_type' => ClickUpTaskType::Design,
            'employee_id' => $busier->id,
            'integration_key' => 'open-hours',
            'status' => 'in progress',
            'planned_hours' => 10,
        ]);
        ClickUpTask::query()->create([
            'request_id' => ServiceRequest::factory()->create()->id,
            'task_type' => ClickUpTaskType::Design,
            'employee_id' => $named->id,
            'integration_key' => 'done-hours',
            'status' => 'complete',
            'planned_hours' => 80,
        ]);
        $request = ServiceRequest::factory()->create([
            'draft_work_lines' => [['department' => 'design', 'hours' => 8]],
            'description' => 'وصف واضح يكفي لتأكيد الخطة دون سؤال إضافي.',
            'status' => RequestStatus::PaymentConfirmed,
            'paid_at' => now(),
        ]);
        $plan = app(ResolveWorkPlan::class)->handle($request);
        $this->assertSame('معرّف', $plan['operations'][0]['employee_name']);

        $task = ClickUpTask::query()->create([
            'request_id' => $request->id,
            'task_type' => ClickUpTaskType::Design,
            'employee_id' => $named->id,
            'integration_key' => 'kept-'.$request->id,
            'clickup_task_id' => 'kept-task',
            'status' => 'in progress',
            'planned_hours' => 3,
        ]);
        $request->forceFill([
            'work_plan' => array_merge($plan, [
                'period_end' => now()->subMonth()->toIso8601String(),
            ]),
            'plan_confirmed_at' => now()->subDay(),
            'subscription_ends_at' => now()->addMonths(2),
        ])->save();

        $again = app(ConfirmWorkPlan::class)->handle($request->fresh(['clickupTasks']) ?? $request);
        $this->assertSame(8, (int) $task->fresh()->planned_hours);
        $this->assertSame('kept-task', $task->fresh()->clickup_task_id);
        $this->assertSame(1, ClickUpTask::query()->where('request_id', $request->id)->count());
        $this->assertTrue($again->plan_confirmed_at->greaterThan(now()->subHour()));

        $review = ClickUpTask::query()->create([
            'request_id' => $request->id,
            'task_type' => ClickUpTaskType::Content,
            'integration_key' => 'still-open-'.$request->id,
            'clickup_task_id' => 'open-task',
            'status' => 'to do',
        ]);
        app(SyncClickUpReview::class)->markApproved($request->fresh() ?? $request);
        $this->assertSame('to do', $review->fresh()->status);
    }

    public function test_calendar_skips_friday_inside_a_long_window_and_text_lines_drop_unknown_departments(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00', 'Asia/Damascus'));
        $calendar = app(WorkCalendar::class);
        $due = $calendar->addWorkHours(now(), 16);
        $this->assertSame('2026-10-03', $due->toDateString());
        $this->assertSame(17, (int) $due->format('G'));

        $calendar->saveHoursPerDay(4);
        $shorterDay = $calendar->addWorkHours(Carbon::parse('2026-10-01 09:00:00', 'Asia/Damascus'), 8);
        $this->assertSame('2026-10-03', $shorterDay->toDateString());

        $lines = WorkLines::fromText("sales 9\ndesign 8\n\nphotography 0");
        $this->assertCount(1, $lines);
        $this->assertSame('design', $lines[0]['department']);
        $this->assertSame(8, $lines[0]['hours']);
    }

    public function test_client_renew_decline_and_revision_routes_keep_files(): void
    {
        Http::fake();
        $client = Client::factory()->create(['telegram_user_id' => 'tg-edge']);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::Completed,
            'paid_at' => now()->subMonth(),
            'billing_period' => 'monthly',
            'allows_renewal' => true,
            'quotation_amount' => 900,
            'amount_total' => 900,
            'amount_paid' => 900,
            'subscription_starts_at' => now()->subMonth(),
            'subscription_ends_at' => now()->addDays(3),
        ]);
        $end = $request->subscription_ends_at->copy();
        DriveDelivery::query()->create([
            'request_id' => $request->id,
            'drive_file_id' => 'keep-file',
            'name' => 'final.png',
            'sent_at' => now(),
        ]);
        Invoice::query()->create([
            'request_id' => $request->id,
            'invoice_number' => 'INV-keep',
            'amount' => 900,
            'kind' => 'full',
            'status' => 'issued',
        ]);

        Client::factory()->create(['telegram_user_id' => 'tg-stranger']);
        $this->clientBot()->postJson("/api/bot/telegram/requests/{$request->id}/renew", [
            'telegram_user_id' => 'tg-missing',
        ])->assertNotFound();
        $this->clientBot()->postJson("/api/bot/telegram/requests/{$request->id}/renew", [
            'telegram_user_id' => 'tg-stranger',
        ])->assertForbidden();

        $this->clientBot()->postJson("/api/bot/telegram/requests/{$request->id}/renew", [
            'telegram_user_id' => 'tg-edge',
        ])->assertOk();
        $this->clientBot()->postJson("/api/bot/telegram/requests/{$request->id}/renew", [
            'telegram_user_id' => 'tg-edge',
        ])->assertOk();
        $this->assertSame(1, $request->subscriptions()->where('status', 'pending_renewal')->count());
        $this->assertTrue($request->fresh()->subscription_ends_at->equalTo($end));
        $this->assertSame('900.00', $request->subscriptions()->where('status', 'pending_renewal')->value('amount'));

        $this->clientBot()->postJson("/api/bot/telegram/requests/{$request->id}/decline-renewal", [
            'telegram_user_id' => 'tg-edge',
        ])->assertOk();
        $this->assertSame(1, DriveDelivery::query()->where('drive_file_id', 'keep-file')->count());
        $this->assertSame(1, Invoice::query()->where('invoice_number', 'INV-keep')->count());
        $this->assertFalse($request->fresh()->canRenew());

        $empty = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::Completed,
            'billing_period' => 'monthly',
            'allows_renewal' => true,
            'subscription_ends_at' => now()->addDay(),
            'quotation_amount' => 0,
            'amount_total' => 0,
        ]);
        try {
            app(RenewSubscription::class)->handle($empty);
            $this->fail('A renewal without an agreed amount should stop.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }
    }

    public function test_revision_route_allows_one_round_and_reminders_change_wording_after_the_invoice(): void
    {
        $this->fakeOutbound();
        config(['services.telegram.bot_token' => 'client-token']);
        $client = Client::factory()->create(['telegram_user_id' => 'tg-rev']);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::InProgress,
            'billing_period' => 'monthly',
            'allows_renewal' => true,
            'subscription_ends_at' => now()->addDays(5),
        ]);
        DriveDelivery::query()->create([
            'request_id' => $request->id,
            'drive_file_id' => 'rev-file',
            'name' => 'poster.png',
            'sent_at' => now(),
        ]);

        Client::factory()->create(['telegram_user_id' => 'tg-other']);
        $this->clientBot()->postJson("/api/bot/telegram/requests/{$request->id}/revision", [
            'telegram_user_id' => 'tg-other',
            'reason' => 'غيّر العنوان',
        ])->assertForbidden();
        $this->clientBot()->postJson("/api/bot/telegram/requests/{$request->id}/revision", [
            'telegram_user_id' => 'tg-rev',
            'reason' => 'غيّر العنوان',
        ])->assertOk();
        $this->clientBot()->postJson("/api/bot/telegram/requests/{$request->id}/revision", [
            'telegram_user_id' => 'tg-rev',
            'reason' => 'مرة ثانية',
        ])->assertUnprocessable()
            ->assertJsonFragment(['التعديل متاح مرة واحدة. للدعم اتصل +963 968 862 822 أو راسل info@hoc.agency.']);

        $waiting = new PaymentReminder([
            'kind' => PaymentReminder::KIND_RENEWAL,
            'due_at' => now()->addDay(),
            'send_count' => 5,
            'last_sent_at' => now()->subDay(),
        ]);
        $this->assertFalse($waiting->isDue());

        Subscription::query()->create([
            'request_id' => $request->id,
            'billing_period' => 'monthly',
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
            'amount' => 100,
            'status' => 'pending_renewal',
        ]);
        PaymentReminder::query()->create([
            'request_id' => $request->id,
            'kind' => PaymentReminder::KIND_RENEWAL,
            'due_at' => now()->subMinute(),
            'send_count' => 0,
        ]);
        $this->artisan('ops:process-reminders')->assertSuccessful();
        $this->assertStringContainsString('بانتظار دفع فاتورة', $this->sentTexts());
    }

    public function test_active_catalog_repeat_does_not_send_a_new_quotation(): void
    {
        Http::fake();
        $client = Client::factory()->create([
            'telegram_user_id' => 'tg-same',
            'name' => 'عميل',
            'phone' => '+963900002222',
            'company_name' => 'الشركة',
        ]);
        $package = $this->package();
        ServiceRequest::factory()->for($client)->create([
            'pricing_package_id' => $package->id,
            'billing_period' => 'monthly',
            'allows_renewal' => true,
            'subscription_ends_at' => now()->addDays(20),
            'status' => RequestStatus::InProgress,
        ]);

        $action = app(CreateCatalogRequest::class);
        $action->handle($client, $package, 'monthly');
        $this->assertFalse($action->quotationDelivered);
    }

    private function fakeOutbound(): void
    {
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response([
            'ok' => true,
            'result' => ['message_id' => 1],
            'messages' => [['id' => 'wamid']],
        ], 200));
    }

    /**
     * @return string
     */
    private function sentTexts(): string
    {
        return Http::recorded()->map(function (array $pair): string {
            $decoded = json_decode((string) $pair[0]->body(), true);
            if (! is_array($decoded)) {
                return (string) $pair[0]->body();
            }

            $text = $decoded['text'] ?? null;
            if (is_array($text)) {
                return (string) ($text['body'] ?? '');
            }

            return is_string($text) ? $text : '';
        })->implode("\n");
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function clientBot(): static
    {
        return $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot', 'Accept' => 'application/json']);
    }

    private function package(): \App\Models\PricingPackage
    {
        $category = \App\Models\PricingCategory::query()->create([
            'slug' => 'edge-cat',
            'name_en' => 'Cat',
            'name_ar' => 'قسم',
            'sort_order' => 1,
            'is_published' => true,
        ]);
        $sub = \App\Models\PricingSubcategory::query()->create([
            'category_id' => $category->id,
            'slug' => 'edge-sub',
            'name_en' => 'Sub',
            'name_ar' => 'فرعي',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        return \App\Models\PricingPackage::query()->create([
            'subcategory_id' => $sub->id,
            'slug' => 'edge-pkg',
            'name_en' => 'Pkg',
            'name_ar' => 'باقة',
            'subtitle_en' => 'Sub',
            'subtitle_ar' => 'فرعي',
            'prices' => ['monthly' => 100],
            'sort_order' => 1,
            'is_published' => true,
        ]);
    }
}
