<?php

namespace Tests\Feature;

use App\Actions\ConfirmRequestPayment;
use App\Actions\DeclineRenewal;
use App\Actions\RenewSubscription;
use App\Actions\ReRequestReceipt;
use App\Actions\SchedulePaymentReminders;
use App\Enums\ClickUpTaskType;
use App\Enums\PaymentMethod;
use App\Enums\RequestStatus;
use App\Models\ClickUpTask;
use App\Models\Client;
use App\Models\DriveDelivery;
use App\Models\PaymentReminder;
use App\Models\PricingCategory;
use App\Models\PricingPackage;
use App\Models\PricingSubcategory;
use App\Models\RequestFile;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\GoogleDriveClient;
use App\Support\PaymentPlanResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesOdooDocuments;
use Tests\TestCase;

class ClientOpsAutomationTest extends TestCase
{
    use FakesOdooDocuments;
    use RefreshDatabase;

    public function test_incomplete_profile_cannot_create_catalog_request(): void
    {
        $this->seedPublishedPackage();

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson('/api/bot/telegram/link', [
                'telegram_user_id' => 'tg-incomplete',
                'name' => 'Ali',
                'locale' => 'ar',
            ])->assertOk()
            ->assertJsonPath('data.profile_complete', false);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->getJson('/api/bot/telegram/me?telegram_user_id=tg-incomplete')
            ->assertOk()
            ->assertJsonPath('data.profile_complete', false)
            ->assertJsonPath('data.missing_fields.0', 'phone');

        $package = PricingPackage::query()->firstOrFail();

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson('/api/bot/telegram/catalog/requests', [
                'telegram_user_id' => 'tg-incomplete',
                'package_id' => $package->id,
                'billing_period' => 'monthly',
            ])->assertStatus(422);
    }

    public function test_complete_profile_creates_odoo_lead_on_telegram_stage(): void
    {
        Http::preventStrayRequests();
        $this->fakeOdooDocuments();
        Http::fake($this->odooDocumentsHttpFake());
        config(['services.gemini.e2e_stub' => true]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson('/api/bot/telegram/link', [
                'telegram_user_id' => 'tg-lead',
                'name' => 'Sara',
                'phone' => '+963911111111',
                'company_name' => 'شركة الإبداع',
                'locale' => 'ar',
            ])->assertOk()
            ->assertJsonPath('data.profile_complete', true);

        $client = Client::query()->where('telegram_user_id', 'tg-lead')->firstOrFail();
        $this->assertSame('77', $client->odoo_lead_id);
        $this->assertSame('خدمات عامة', $client->company_activity);
    }

    public function test_catalog_tree_hides_prices_and_quotes_published_package(): void
    {
        $package = $this->seedPublishedPackage();

        $this->completeClient('tg-catalog');

        $tree = $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->getJson('/api/bot/telegram/catalog?telegram_user_id=tg-catalog')
            ->assertOk()
            ->json('data');

        $this->assertSame('categories', $tree['kind']);
        $this->assertArrayNotHasKey('price', $tree['items'][0]);
        $this->assertSame($package->subcategory->category->name_ar, $tree['items'][0]['name']);

        $created = $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson('/api/bot/telegram/catalog/requests', [
                'telegram_user_id' => 'tg-catalog',
                'package_id' => $package->id,
                'billing_period' => 'monthly',
            ])->assertCreated()
            ->json('data');

        $this->assertSame('quotation_sent', $created['status']);
        $this->assertTrue($created['allows_renewal']);
        $this->assertFalse($created['requires_full_payment']);
        $this->assertDatabaseHas('requests', [
            'number' => $created['number'],
            'pricing_package_id' => $package->id,
            'billing_period' => 'monthly',
            'allows_renewal' => true,
        ]);
    }

    public function test_every_category_has_allows_renewal_and_reach_is_full_payment(): void
    {
        $reach = PricingCategory::query()->create([
            'slug' => 'reach',
            'name_en' => 'Paid ads',
            'name_ar' => 'الإعلانات الممولة والانتشار',
            'sort_order' => 1,
            'is_published' => true,
            'requires_full_payment' => true,
            'allows_renewal' => false,
        ]);
        $retainer = PricingCategory::query()->create([
            'slug' => 'strategic',
            'name_en' => 'Strategic',
            'name_ar' => 'استراتيجي',
            'sort_order' => 2,
            'is_published' => true,
            'requires_full_payment' => false,
            'allows_renewal' => true,
        ]);

        $this->assertFalse($reach->allows_renewal);
        $this->assertTrue($retainer->allows_renewal);

        $sub = PricingSubcategory::query()->create([
            'category_id' => $reach->id,
            'slug' => 'ads',
            'name_en' => 'Ads',
            'name_ar' => 'إعلانات',
            'sort_order' => 1,
            'is_published' => true,
        ]);
        $package = PricingPackage::query()->create([
            'subcategory_id' => $sub->id,
            'slug' => 'boost',
            'name_en' => 'Boost',
            'name_ar' => 'تعزيز',
            'subtitle_en' => 'Reach',
            'subtitle_ar' => 'انتشار',
            'prices' => ['monthly' => 500],
            'reach' => ['adBudgetUsd' => 200],
            'allows_partial_payment' => false,
            'sort_order' => 1,
            'is_published' => true,
        ]);
        $package->load('subcategory.category');

        $plan = PaymentPlanResolver::forPackage($package);
        $this->assertTrue($plan['requires_full_payment']);
        $this->assertFalse($plan['allows_renewal']);
    }

    public function test_reject_records_reason_and_receipt_rerequest_targets_that_request(): void
    {
        $package = $this->seedPublishedPackage();
        $this->completeClient('tg-reject');

        $created = $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson('/api/bot/telegram/catalog/requests', [
                'telegram_user_id' => 'tg-reject',
                'package_id' => $package->id,
                'billing_period' => 'monthly',
            ])->assertCreated()
            ->json('data');

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson('/api/bot/telegram/requests/'.$created['number'].'/reject', [
                'telegram_user_id' => 'tg-reject',
                'reason' => 'السعر غالي',
            ])->assertOk();

        $this->assertDatabaseHas('quotation_decisions', [
            'request_id' => $created['id'],
            'decision' => 'rejected',
        ]);

        $request = ServiceRequest::query()->findOrFail($created['id']);
        $request->forceFill(['status' => RequestStatus::AwaitingPayment])->save();
        RequestFile::query()->create([
            'request_id' => $request->id,
            'kind' => 'payment_receipt',
            'original_name' => 'old.jpg',
            'path' => 'receipts/old.jpg',
        ]);
        Storage::fake('local');
        Storage::disk('local')->put('receipts/old.jpg', 'old');

        app(ReRequestReceipt::class)->handle($request, 'غير واضح');
        $request->refresh();
        $this->assertTrue($request->receipt_reupload_required);
        $this->assertSame(0, $request->files()->where('kind', 'payment_receipt')->count());
    }

    public function test_renewal_stacks_ends_at_only_when_category_allows_it(): void
    {
        $package = $this->seedPublishedPackage(allowsRenewal: false);
        $this->completeClient('tg-norenew');
        $created = $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson('/api/bot/telegram/catalog/requests', [
                'telegram_user_id' => 'tg-norenew',
                'package_id' => $package->id,
                'billing_period' => 'monthly',
            ])->json('data');

        $blocked = ServiceRequest::query()->findOrFail($created['id']);
        $this->expectException(ValidationException::class);
        app(RenewSubscription::class)->handle($blocked);
    }

    public function test_renewal_extends_previous_end_and_tapered_reminders_respect_flag(): void
    {
        $package = $this->seedPublishedPackage(allowsRenewal: true);
        $this->completeClient('tg-renew');
        $created = $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson('/api/bot/telegram/catalog/requests', [
                'telegram_user_id' => 'tg-renew',
                'package_id' => $package->id,
                'billing_period' => 'monthly',
            ])->json('data');

        $request = ServiceRequest::query()->findOrFail($created['id']);
        $request->forceFill([
            'status' => RequestStatus::PaymentConfirmed,
            'paid_at' => now()->subDays(20),
            'amount_total' => 400,
            'amount_paid' => 400,
            'amount_remaining' => 0,
            'subscription_starts_at' => now()->subDays(20),
            'subscription_ends_at' => now()->addDays(10),
            'allows_renewal' => true,
            'billing_period' => 'monthly',
        ])->save();

        $previousEnd = $request->subscription_ends_at->copy();
        $renewed = app(RenewSubscription::class)->handle($request->fresh() ?? $request);
        $this->assertTrue($renewed->subscription_ends_at->greaterThan($previousEnd));
        $this->assertEqualsWithDelta(30, $previousEnd->diffInDays($renewed->subscription_ends_at), 2);

        $blocked = $request->fresh() ?? $request;
        $blocked->forceFill(['allows_renewal' => false])->save();
        app(DeclineRenewal::class)->handle($request->fresh(['subscriptions']) ?? $request);

        $partial = $request->fresh() ?? $request;
        $partial->forceFill([
            'allows_renewal' => true,
            'payment_plan' => 'partial',
            'amount_remaining' => 200,
            'subscription_starts_at' => now()->subDays(10),
            'subscription_ends_at' => now()->addDays(10),
        ])->save();
        app(SchedulePaymentReminders::class)->handle($partial->fresh(['subscriptions']) ?? $partial);

        $this->assertTrue(
            PaymentReminder::query()->where('request_id', $partial->id)->where('kind', PaymentReminder::KIND_REMAINING)->exists()
        );
        $this->assertTrue(
            PaymentReminder::query()->where('request_id', $partial->id)->where('kind', PaymentReminder::KIND_RENEWAL)->exists()
        );

        $reminder = PaymentReminder::query()->where('kind', PaymentReminder::KIND_RENEWAL)->firstOrFail();
        $reminder->forceFill(['due_at' => now()->subMinute(), 'send_count' => 0])->save();
        $partial->forceFill(['allows_renewal' => false])->save();
        $this->artisan('ops:process-reminders')->assertSuccessful();
        $this->assertNotNull($reminder->fresh()?->completed_at);
    }

    public function test_partial_payment_confirm_does_not_mark_won_until_fully_paid(): void
    {
        $client = Client::factory()->create([
            'telegram_user_id' => 'tg-pay',
            'company_name' => 'شركة',
            'phone' => '099',
        ]);
        $request = ServiceRequest::factory()->create([
            'client_id' => $client->id,
            'status' => RequestStatus::AwaitingPayment,
            'quotation_amount' => 1000,
            'amount_total' => 1000,
            'amount_paid' => 0,
            'amount_remaining' => 1000,
            'requires_full_payment' => false,
            'payment_plan' => 'partial',
            'billing_period' => 'monthly',
        ]);

        config(['services.gemini.e2e_stub' => true]);
        app(ConfirmRequestPayment::class)->handle($request, PaymentMethod::Receipt);
        $request->refresh();
        $this->assertNull($request->odoo_won_at);
        $this->assertGreaterThan(0, (float) $request->amount_remaining);

        app(ConfirmRequestPayment::class)->handle($request->fresh() ?? $request, PaymentMethod::Receipt);
        $this->assertNotNull($request->fresh()?->odoo_won_at);
    }

    public function test_admin_can_toggle_category_renewal_and_upload_qr(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $category = PricingCategory::query()->create([
            'slug' => 'all-cats',
            'name_en' => 'All',
            'name_ar' => 'الكل',
            'sort_order' => 1,
            'is_published' => true,
            'allows_renewal' => false,
            'requires_full_payment' => false,
        ]);

        $this->putJson('/api/admin/pricing/categories/'.$category->id, [
            'allows_renewal' => true,
            'requires_full_payment' => true,
        ])->assertOk()
            ->assertJsonPath('data.allows_renewal', true)
            ->assertJsonPath('data.requires_full_payment', true);

        $file = UploadedFile::fake()->image('qr.png');
        $this->post('/api/admin/ops-settings/sham-cash-qr', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertOk();
        $this->getJson('/api/admin/ops-settings')->assertOk()->assertJsonPath('data.sham_cash_qr', true);
    }

    public function test_clickup_due_alert_is_idempotent_and_covers_just_passed_due(): void
    {
        Cache::flush();
        Http::fake([
            'https://api.clickup.com/*' => Http::response([
                'tasks' => [[
                    'id' => 'cu-due-1',
                    'name' => 'غلاف',
                    'due_date' => (string) (now()->subMinutes(30)->timestamp * 1000),
                    'url' => 'https://app.clickup.com/t/cu-due-1',
                ]],
            ], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        config([
            'services.clickup.token' => 'pk_test',
            'services.clickup.list_id' => 'list-sales',
            'services.clickup.lists.sales' => 'list-sales',
            'services.clickup.lists.photography' => null,
            'services.clickup.lists.content' => null,
            'services.clickup.lists.design' => null,
            'services.clickup.lists.programming' => null,
            'services.telegram.admin_bot_token' => 'admin-token',
            'services.telegram.admin_telegram_ids' => ['9001'],
        ]);

        $request = ServiceRequest::factory()->create();
        ClickUpTask::query()->create([
            'request_id' => $request->id,
            'task_type' => ClickUpTaskType::Design,
            'clickup_task_id' => 'cu-due-1',
            'integration_key' => 'due-alert-1',
        ]);

        $this->artisan('ops:clickup-due-alerts')->assertSuccessful();
        $this->artisan('ops:clickup-due-alerts')->assertSuccessful();

        $messages = collect(Http::recorded())
            ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), 'sendMessage'));
        $this->assertCount(1, $messages);
        $this->assertStringContainsString($request->number, (string) $messages->first()[0]['text']);
    }

    public function test_clickup_due_alert_falls_back_to_staff_chat(): void
    {
        Cache::flush();
        Http::fake([
            'https://api.clickup.com/*' => Http::response([
                'tasks' => [[
                    'id' => 'cu-due-staff',
                    'name' => 'مهمة',
                    'due_date' => (string) (now()->addHours(6)->timestamp * 1000),
                ]],
            ], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        config([
            'services.clickup.token' => 'pk_test',
            'services.clickup.lists.sales' => 'list-sales',
            'services.telegram.admin_bot_token' => null,
            'services.telegram.admin_telegram_ids' => [],
            'services.telegram.staff_bot_token' => 'staff-token',
            'services.telegram.staff_chat_id' => '555',
        ]);

        $this->artisan('ops:clickup-due-alerts')->assertSuccessful();

        Http::assertSent(fn ($httpRequest): bool => str_contains($httpRequest->url(), 'botstaff-token/sendMessage')
            && (string) $httpRequest['chat_id'] === '555');
    }

    public function test_drive_poll_retries_until_telegram_send_succeeds(): void
    {
        Cache::flush();
        Storage::fake('local');
        config(['services.telegram.bot_token' => null]);

        $client = Client::factory()->create(['telegram_user_id' => 'tg-drive']);
        ServiceRequest::factory()->create([
            'client_id' => $client->id,
            'google_drive_folder_id' => 'folder-1',
        ]);

        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('listNewFiles')->andReturn([
                ['id' => 'file-1', 'name' => 'logo.png', 'mimeType' => 'image/png'],
            ]);
            $mock->shouldReceive('downloadFile')->with('file-1')->andReturn('PNG');
        });

        $this->artisan('ops:poll-drive')->assertSuccessful();
        $delivery = DriveDelivery::query()->where('drive_file_id', 'file-1')->first();
        $this->assertNotNull($delivery);
        $this->assertNull($delivery->sent_at);

        config(['services.telegram.bot_token' => 'client-token']);
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['photo' => [['file_id' => 'tg-photo']]],
            ], 200),
        ]);

        $this->artisan('ops:poll-drive')->assertSuccessful();
        $this->assertNotNull($delivery->fresh()?->sent_at);
    }

    public function test_admin_bot_forbidden_without_allowlist(): void
    {
        $this->withHeaders(['X-Webhook-Secret' => 'change-me-admin'])
            ->getJson('/api/bot/admin/me?telegram_user_id=1')
            ->assertForbidden();
    }

    private function completeClient(string $telegramId): Client
    {
        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson('/api/bot/telegram/link', [
                'telegram_user_id' => $telegramId,
                'name' => 'Client '.$telegramId,
                'phone' => '+963900000000',
                'company_name' => 'شركة '.$telegramId,
                'locale' => 'ar',
            ])->assertOk();

        return Client::query()->where('telegram_user_id', $telegramId)->firstOrFail();
    }

    private function seedPublishedPackage(bool $allowsRenewal = true): PricingPackage
    {
        $category = PricingCategory::query()->create([
            'slug' => 'retainers-'.$allowsRenewal,
            'name_en' => 'Retainers',
            'name_ar' => 'اشتراكات',
            'sort_order' => 1,
            'is_published' => true,
            'requires_full_payment' => false,
            'allows_renewal' => $allowsRenewal,
        ]);
        $sub = PricingSubcategory::query()->create([
            'category_id' => $category->id,
            'slug' => 'monthly-'.$allowsRenewal,
            'name_en' => 'Monthly',
            'name_ar' => 'شهري',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        return PricingPackage::query()->create([
            'subcategory_id' => $sub->id,
            'slug' => 'startup-'.$allowsRenewal,
            'name_en' => 'Startup',
            'name_ar' => 'Startup',
            'subtitle_en' => 'Small',
            'subtitle_ar' => 'صغير',
            'prices' => ['monthly' => 399, 'quarterly' => 1137],
            'features' => [],
            'sort_order' => 1,
            'is_published' => true,
        ]);
    }
}
