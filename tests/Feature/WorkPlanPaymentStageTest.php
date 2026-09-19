<?php

namespace Tests\Feature;

use App\Actions\EnqueueIntegrationEvent;
use App\Actions\IssueInvoice;
use App\Actions\ProvisionClickUpTasks;
use App\Actions\ResolveWorkPlan;
use App\Enums\EmployeeProfession;
use App\Enums\GeminiStatus;
use App\Enums\RequestStatus;
use App\Enums\WorkType;
use App\Jobs\ClassifyWithGeminiJob;
use App\Models\Client;
use App\Models\Employee;
use App\Models\PricingCategory;
use App\Models\PricingPackage;
use App\Models\PricingSubcategory;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\RequestStatusTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkPlanPaymentStageTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_sends_work_plan_and_confirm_payment_button(): void
    {
        Http::preventStrayRequests();
        config([
            'services.telegram.staff_bot_token' => 'staff-token',
            'services.gemini.e2e_stub' => true,
        ]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        Employee::factory()->sales()->create(['telegram_user_id' => '6350001']);
        Employee::factory()->create([
            'profession' => EmployeeProfession::Design,
            'name' => 'مصمم أول',
            'clickup_user_id' => '111',
        ]);
        $client = Client::factory()->create(['telegram_user_id' => 'tg-plan-1']);
        $serviceRequest = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::AwaitingPayment,
            'title' => 'هوية بصرية',
            'quotation_amount' => 400,
            'amount_total' => 400,
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$serviceRequest->number}/receipt", [
                'telegram_user_id' => 'tg-plan-1',
                'file_name' => 'receipt.jpg',
                'file_base64' => base64_encode('fake-image'),
                'mime_type' => 'image/jpeg',
            ])->assertOk();

        $serviceRequest->refresh();
        $this->assertNotEmpty($serviceRequest->work_plan['operations'] ?? []);
        $this->assertSame('مصمم أول', $serviceRequest->work_plan['operations'][0]['employee_name'] ?? null);

        Http::assertSent(function (Request $request): bool {
            $body = $request->body();

            return str_contains($request->url(), 'botstaff-token/sendPhoto')
                && str_contains($body, 'رفع الزبون وصل دفع')
                && str_contains($body, 'خطة العمل')
                && str_contains($body, 'payok:');
        });
    }

    public function test_package_work_plan_is_cached_for_the_second_request(): void
    {
        config(['services.gemini.e2e_stub' => true]);
        $package = $this->makePackage();
        $first = ServiceRequest::factory()->create([
            'title' => 'باقة شهرية',
            'pricing_package_id' => $package->id,
        ]);
        $second = ServiceRequest::factory()->create([
            'title' => 'باقة شهرية مكررة',
            'pricing_package_id' => $package->id,
        ]);

        $firstPlan = app(ResolveWorkPlan::class)->handle($first);
        $this->assertSame('ai', $firstPlan['source']);
        $this->assertTrue(Cache::has('hoc:work-plan:v1:pkg:'.$package->id));

        $secondPlan = app(ResolveWorkPlan::class)->handle($second);
        $this->assertSame('cache', $secondPlan['source']);
        $this->assertSame(
            array_column($firstPlan['operations'], 'department'),
            array_column($secondPlan['operations'], 'department'),
        );
    }

    public function test_staff_confirm_payment_button_confirms_expected_amount(): void
    {
        Http::fake();
        Bus::fake([ClassifyWithGeminiJob::class]);
        config(['services.gemini.e2e_stub' => true]);

        Employee::factory()->sales()->create(['telegram_user_id' => 'sales-payok']);
        $request = ServiceRequest::factory()->create([
            'status' => RequestStatus::AwaitingPayment,
            'quotation_amount' => 200,
            'amount_total' => 200,
            'requires_full_payment' => true,
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->postJson('/api/bot/staff/confirm-payment', [
                'telegram_user_id' => 'sales-payok',
                'request_number' => $request->number,
            ])->assertOk()
            ->assertJsonPath('data.confirmed', true)
            ->assertJsonPath('data.status', 'payment_confirmed')
            ->assertJsonPath('data.amount', 200);

        $this->assertSame(RequestStatus::PaymentConfirmed, $request->fresh()->status);
        Bus::assertDispatched(ClassifyWithGeminiJob::class);
    }

    public function test_classify_job_skips_gemini_when_work_plan_exists(): void
    {
        Http::preventStrayRequests();
        config([
            'services.gemini.e2e_stub' => false,
            'services.gemini.api_key' => 'should-not-be-called',
            'services.google_translate.enabled' => false,
        ]);

        $request = ServiceRequest::factory()->create([
            'status' => RequestStatus::PaymentConfirmed,
            'gemini_status' => GeminiStatus::Pending,
            'work_plan' => [
                'source' => 'cache',
                'operations' => [[
                    'department' => 'design',
                    'brief' => 'تصميم الشعار من الكاش',
                    'hours' => 24,
                    'priority' => 2,
                    'clickup_user_id' => '111',
                ]],
            ],
        ]);

        (new ClassifyWithGeminiJob($request->id))->handle(
            app(GeminiService::class),
            app(RequestStatusTransitionService::class),
            app(EnqueueIntegrationEvent::class),
            app(IssueInvoice::class),
            app(ProvisionClickUpTasks::class),
        );

        $request->refresh();
        $this->assertSame(GeminiStatus::Success, $request->gemini_status);
        $this->assertSame(WorkType::Design, $request->work_type);
        $this->assertDatabaseHas('department_briefs', [
            'request_id' => $request->id,
            'type' => 'design',
            'brief' => 'تصميم الشعار من الكاش',
        ]);
        Http::assertNothingSent();
    }

    public function test_clickup_tasks_use_assignee_due_and_priority_from_work_plan(): void
    {
        Http::preventStrayRequests();
        config([
            'services.clickup.token' => 'pk_test',
            'services.clickup.list_id' => '901821114103',
            'services.clickup.lists.design' => '901821114103',
        ]);
        Http::fake([
            'https://api.clickup.com/*' => Http::response(['id' => 'cu-assigned-1'], 200),
        ]);

        $request = ServiceRequest::factory()->create([
            'status' => RequestStatus::PaymentConfirmed,
            'work_type' => WorkType::Design,
            'work_plan' => [
                'source' => 'cache',
                'operations' => [[
                    'department' => 'design',
                    'brief' => 'تنفيذ الهوية',
                    'hours' => 48,
                    'priority' => 2,
                    'due_at' => now()->addHours(48)->toIso8601String(),
                    'clickup_user_id' => '222333',
                    'employee_name' => 'مصمم أول',
                ]],
            ],
        ]);
        $request->briefs()->create([
            'department' => 'design',
            'type' => 'design',
            'brief' => 'تنفيذ الهوية',
        ]);

        app(ProvisionClickUpTasks::class)->handle($request, 'event-work-plan');

        Http::assertSent(function (Request $httpRequest): bool {
            if (! str_contains($httpRequest->url(), 'api.clickup.com/api/v2/list/901821114103/task')) {
                return false;
            }
            $payload = $httpRequest->data();

            return ($payload['assignees'][0] ?? null) === 222333
                && (int) ($payload['priority'] ?? 0) === 2
                && isset($payload['due_date']);
        });
    }

    public function test_quotation_approve_includes_payok_button(): void
    {
        Http::preventStrayRequests();
        config([
            'services.telegram.staff_bot_token' => 'staff-token',
            'services.gemini.e2e_stub' => true,
        ]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        Employee::factory()->sales()->create(['telegram_user_id' => '6350001']);
        $client = Client::factory()->create(['telegram_user_id' => '213309826']);
        $serviceRequest = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::QuotationSent,
            'quotation_amount' => 250,
        ]);
        Quotation::query()->create([
            'request_id' => $serviceRequest->id,
            'version' => 1,
            'amount' => 250,
            'sent_at' => now(),
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$serviceRequest->number}/approve", [
                'telegram_user_id' => '213309826',
            ])->assertOk();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'botstaff-token/sendMessage')
            && str_contains((string) $request['text'], 'وافق الزبون على عرض السعر')
            && str_contains((string) $request['text'], 'خطة العمل')
            && str_contains(json_encode($request['reply_markup'], JSON_UNESCAPED_UNICODE) ?: '', 'payok:'));
    }

    public function test_admin_request_resource_includes_work_plan(): void
    {
        Http::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);
        $request = ServiceRequest::factory()->create([
            'work_plan' => [
                'source' => 'cache',
                'operations' => [[
                    'department' => 'content',
                    'brief' => 'كتابة المنشورات',
                    'employee_name' => 'كاتب',
                    'priority_label' => 'عادية',
                    'hours' => 24,
                ]],
            ],
        ]);

        $this->getJson("/api/admin/requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.work_plan.operations.0.department', 'content')
            ->assertJsonPath('data.work_plan.operations.0.employee_name', 'كاتب');
    }

    private function makePackage(): PricingPackage
    {
        $category = PricingCategory::query()->create([
            'slug' => 'branding-plan',
            'name_en' => 'Branding',
            'name_ar' => 'هوية',
            'sort_order' => 1,
            'is_published' => true,
            'requires_full_payment' => false,
            'allows_renewal' => true,
        ]);
        $sub = PricingSubcategory::query()->create([
            'category_id' => $category->id,
            'slug' => 'monthly-plan',
            'name_en' => 'Monthly',
            'name_ar' => 'شهري',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        return PricingPackage::query()->create([
            'subcategory_id' => $sub->id,
            'slug' => 'startup-plan',
            'name_en' => 'Startup',
            'name_ar' => 'Startup',
            'subtitle_en' => 'Small',
            'subtitle_ar' => 'صغير',
            'prices' => ['monthly' => 399],
            'features' => [['ar' => 'تصميم هوية'], ['ar' => 'كتابة محتوى']],
            'sort_order' => 1,
            'is_published' => true,
        ]);
    }
}
