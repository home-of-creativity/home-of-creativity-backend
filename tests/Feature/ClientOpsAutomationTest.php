<?php

namespace Tests\Feature;

use App\Actions\ConfirmRequestPayment;
use App\Actions\DeclineRenewal;
use App\Actions\EnsureRequestDriveFolder;
use App\Actions\RenewSubscription;
use App\Actions\ReRequestReceipt;
use App\Actions\SchedulePaymentReminders;
use App\Enums\ClickUpTaskType;
use App\Enums\EmployeeProfession;
use App\Enums\EmployeeStatus;
use App\Enums\PaymentMethod;
use App\Enums\RequestStatus;
use App\Models\ClickUpTask;
use App\Models\Client;
use App\Models\DriveDelivery;
use App\Models\Employee;
use App\Models\OpsSetting;
use App\Models\PaymentReminder;
use App\Models\PricingCategory;
use App\Models\PricingPackage;
use App\Models\PricingSubcategory;
use App\Models\Quotation;
use App\Models\RequestFile;
use App\Models\Revision;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\GoogleDriveClient;
use App\Support\PaymentPlanResolver;
use App\Support\ResolveServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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

    public function test_incomplete_telegram_profile_does_not_push_odoo(): void
    {
        Http::preventStrayRequests();
        $this->fakeOdooDocuments();
        Http::fake($this->odooDocumentsHttpFake());
        config(['services.gemini.e2e_stub' => true]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson('/api/bot/telegram/link', [
                'telegram_user_id' => 'tg-no-odoo',
                'name' => 'Ali',
                'locale' => 'ar',
            ])->assertOk()
            ->assertJsonPath('data.profile_complete', false);

        $client = Client::query()->where('telegram_user_id', 'tg-no-odoo')->firstOrFail();
        $this->assertNull($client->odoo_partner_id);
        $this->assertNull($client->odoo_lead_id);

        Http::assertNotSent(function (Request $request): bool {
            $args = $request->data()['params']['args'] ?? [];
            $action = $args[4] ?? null;

            return in_array($action, ['create', 'createOrReusePartner'], true)
                && in_array($args[3] ?? null, ['res.partner', 'crm.lead'], true);
        });
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
        $this->assertSame('عرض سعر مرسل', $created['status_label']);
        $this->assertArrayHasKey('quotation_delivered', $created);
        $this->assertTrue($created['allows_renewal']);
        $this->assertFalse($created['requires_full_payment']);
        $this->assertDatabaseHas('requests', [
            'number' => $created['number'],
            'pricing_package_id' => $package->id,
            'billing_period' => 'monthly',
            'allows_renewal' => true,
        ]);
    }

    public function test_catalog_request_creates_nested_drive_folder(): void
    {
        $package = $this->seedPublishedPackage();
        $this->completeClient('tg-drive-tree');

        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('ensureFolderPath')
                ->once()
                ->withArgs(function (string $parent, array $segments): bool {
                    return ($segments[0] ?? '') !== ''
                        && ($segments[1] ?? '') === 'Startup — شهري'
                        && str_contains((string) ($segments[2] ?? ''), 'Startup');
                })
                ->andReturn('folder-catalog');
        });

        $created = $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson('/api/bot/telegram/catalog/requests', [
                'telegram_user_id' => 'tg-drive-tree',
                'package_id' => $package->id,
                'billing_period' => 'monthly',
            ])->assertCreated()
            ->json('data');

        $this->assertDatabaseHas('requests', [
            'number' => $created['number'],
            'google_drive_folder_id' => 'folder-catalog',
        ]);
    }

    public function test_catalog_quotation_sends_period_once_to_client(): void
    {
        $this->fakeOdooDocuments();
        Http::fake(array_merge($this->odooDocumentsHttpFake(), [
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]));
        config(['services.telegram.bot_token' => 'client-token']);

        $package = $this->seedPublishedPackage();
        $this->completeClient('tg-period-once');

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson('/api/bot/telegram/catalog/requests', [
                'telegram_user_id' => 'tg-period-once',
                'package_id' => $package->id,
                'billing_period' => 'monthly',
            ])
            ->assertCreated();

        Http::assertSent(function (Request $httpRequest): bool {
            if (! str_contains($httpRequest->url(), 'api.telegram.org')) {
                return false;
            }

            $payload = $httpRequest->data();
            $text = (string) ($payload['text'] ?? $payload['caption'] ?? $httpRequest->body());
            if (! str_contains($text, 'عرض سعر') && ! str_contains($text, 'الفترة:')) {
                return false;
            }

            return substr_count($text, 'الفترة:') === 1
                && ! str_contains($text, 'وصف الطلب:')
                && ! str_contains($text, 'تم إنشاء الطلب');
        });
        Http::assertNotSent(function (Request $httpRequest): bool {
            if (! str_contains($httpRequest->url(), 'api.telegram.org') || ! str_contains($httpRequest->url(), 'sendMessage')) {
                return false;
            }

            $text = (string) ($httpRequest->data()['text'] ?? '');

            return str_contains($text, 'تم إنشاء الطلب')
                || str_contains($text, 'وإرسال عرض السعر')
                || $text === 'اختر:';
        });
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
            'status' => RequestStatus::Completed,
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
            'status' => RequestStatus::PaymentConfirmed,
            'allows_renewal' => true,
            'payment_plan' => 'partial',
            'amount_total' => 400,
            'amount_paid' => 200,
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
        app(ConfirmRequestPayment::class)->handle($request, PaymentMethod::Receipt, 400);
        $request->refresh();
        $this->assertNull($request->odoo_won_at);
        $this->assertGreaterThan(0, (float) $request->amount_remaining);

        app(ConfirmRequestPayment::class)->handle($request->fresh() ?? $request, PaymentMethod::Receipt, 600);
        $this->assertNotNull($request->fresh()?->odoo_won_at);
    }

    public function test_full_payment_writes_won_expected_revenue_on_odoo_lead(): void
    {
        Http::preventStrayRequests();
        $this->fakeOdooDocuments();
        Http::fake($this->odooDocumentsHttpFake());
        config(['services.gemini.e2e_stub' => true]);

        $client = Client::factory()->create([
            'telegram_user_id' => 'tg-won-rev',
            'company_name' => 'شركة',
            'phone' => '099',
            'odoo_lead_id' => '77',
        ]);
        $first = ServiceRequest::factory()->create([
            'client_id' => $client->id,
            'status' => RequestStatus::AwaitingPayment,
            'quotation_amount' => 1499,
            'amount_total' => 1499,
            'amount_paid' => 0,
            'amount_remaining' => 1499,
        ]);
        $second = ServiceRequest::factory()->create([
            'client_id' => $client->id,
            'status' => RequestStatus::PaymentConfirmed,
            'quotation_amount' => 800,
            'amount_total' => 800,
            'amount_paid' => 800,
            'amount_remaining' => 0,
            'odoo_won_at' => now()->subDay(),
            'paid_at' => now()->subDay(),
        ]);

        app(ConfirmRequestPayment::class)->handle($first, PaymentMethod::Cash, 1499);

        $this->assertNotNull($first->fresh()?->odoo_won_at);
        $this->assertEqualsWithDelta(2299.0, $client->fresh()?->pipelineRevenue(wonOnly: true), 0.01);

        Http::assertSent(function (Request $request): bool {
            $args = $request->data()['params']['args'] ?? [];
            if (($args[3] ?? null) !== 'crm.lead' || ($args[4] ?? null) !== 'write') {
                return false;
            }

            $vals = $args[5][1] ?? [];

            return (int) ($vals['expected_revenue'] ?? 0) === 2299
                && (int) ($vals['probability'] ?? 0) === 100;
        });
    }

    public function test_confirm_payment_posts_and_registers_odoo_invoice_payment(): void
    {
        Http::preventStrayRequests();
        $this->fakeOdooDocuments();
        Http::fake($this->odooDocumentsHttpFake());
        config(['services.gemini.e2e_stub' => true]);

        $client = Client::factory()->create([
            'telegram_user_id' => 'tg-odoo-paid',
            'company_name' => 'شركة',
            'phone' => '099',
            'odoo_partner_id' => '44',
        ]);
        $request = ServiceRequest::factory()->create([
            'client_id' => $client->id,
            'status' => RequestStatus::AwaitingPayment,
            'quotation_amount' => 3830,
            'amount_total' => 3830,
            'amount_paid' => 0,
            'amount_remaining' => 3830,
            'odoo_quotation_id' => '51',
        ]);

        app(ConfirmRequestPayment::class)->handle($request, PaymentMethod::Cash, 3830);

        $this->assertSame('501', $request->fresh()?->odoo_invoice_id);

        Http::assertSent(function (Request $request): bool {
            $args = $request->data()['params']['args'] ?? [];

            return ($args[3] ?? null) === 'account.move'
                && ($args[4] ?? null) === 'action_post'
                && (int) (($args[5][0][0] ?? 0) ?: 0) === 501;
        });

        Http::assertSent(function (Request $request): bool {
            $args = $request->data()['params']['args'] ?? [];
            $context = $args[6]['context'] ?? [];

            return ($args[3] ?? null) === 'account.payment.register'
                && ($args[4] ?? null) === 'create'
                && (($context['active_model'] ?? null) === 'account.move')
                && in_array(501, $context['active_ids'] ?? [], true);
        });

        Http::assertSent(function (Request $request): bool {
            $args = $request->data()['params']['args'] ?? [];

            return ($args[3] ?? null) === 'account.payment.register'
                && ($args[4] ?? null) === 'action_create_payments';
        });
    }

    public function test_invoice_uses_actual_received_amount_not_requested_deposit(): void
    {
        Http::fake();
        $client = Client::factory()->create([
            'telegram_user_id' => 'tg-invoice',
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
        ]);

        config(['services.gemini.e2e_stub' => true]);
        $updated = app(ConfirmRequestPayment::class)->handle($request, PaymentMethod::Receipt, 400);
        $invoice = $updated->invoices()->latest('id')->first();

        $this->assertNotNull($invoice);
        $this->assertSame('received', $invoice->kind);
        $this->assertEqualsWithDelta(400, (float) $invoice->amount, 0.01);
        $this->assertEqualsWithDelta(400, (float) $updated->amount_paid, 0.01);
        $this->assertEqualsWithDelta(600, (float) $updated->amount_remaining, 0.01);
        $this->assertEqualsWithDelta(40.0, $updated->paidPercent(), 0.1);
        $this->assertEqualsWithDelta(60.0, $updated->remainingPercent(), 0.1);
        $this->assertSame(1, $updated->invoices()->count());
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
        $this->getJson('/api/admin/ops-settings')->assertOk()
            ->assertJsonPath('data.sham_cash_qr', true);
        $this->assertNotEmpty($this->getJson('/api/admin/ops-settings')->json('data.sham_cash_qr_updated_at'));
        $this->get('/api/admin/ops-settings/sham-cash-qr')->assertOk();
        $this->putJson('/api/admin/ops-settings/social-profile', [
            'display_name' => 'Home of Creativity',
            'bio' => 'Brand architects',
            'theme' => 'cream',
        ])->assertOk()
            ->assertJsonPath('data.theme', 'cream')
            ->assertJsonPath('data.display_name', 'Home of Creativity');
    }

    public function test_approving_quotation_returns_shared_sham_cash_qr_for_the_bot(): void
    {
        Storage::fake('local');
        Http::fake();

        $path = UploadedFile::fake()->image('sham-cash.png')->store('payment', 'local');
        OpsSetting::setValue('sham_cash_qr_path', $path);

        $client = Client::factory()->create(['telegram_user_id' => 'tg-sham-cash']);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::QuotationSent,
        ]);
        Quotation::query()->create([
            'request_id' => $request->id,
            'version' => 1,
            'amount' => 800,
            'sent_at' => now(),
        ]);

        $response = $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$request->number}/approve", [
                'telegram_user_id' => 'tg-sham-cash',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonPath('sham_cash_qr.qr_available', true)
            ->assertJsonPath('sham_cash_qr.delivered', false)
            ->assertJsonPath('sham_cash_qr.file_name', basename((string) $path));

        $this->assertNotEmpty($response->json('sham_cash_qr.content_base64'));

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->get('/api/bot/telegram/sham-cash-qr')
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
    }

    public function test_approving_quotation_sends_sham_cash_qr_when_telegram_is_configured(): void
    {
        Storage::fake('local');
        config(['services.telegram.bot_token' => 'client-token']);
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['photo' => [['file_id' => 'qr-1']]],
            ], 200),
        ]);

        $path = UploadedFile::fake()->image('sham-cash.png')->store('payment', 'local');
        OpsSetting::setValue('sham_cash_qr_path', $path);

        $client = Client::factory()->create(['telegram_user_id' => 'tg-sham-sent']);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::QuotationSent,
            'quotation_amount' => 400,
        ]);
        Quotation::query()->create([
            'request_id' => $request->id,
            'version' => 1,
            'amount' => 400,
            'sent_at' => now(),
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$request->number}/approve", [
                'telegram_user_id' => 'tg-sham-sent',
            ])
            ->assertOk()
            ->assertJsonPath('sham_cash_qr.qr_available', true)
            ->assertJsonPath('sham_cash_qr.delivered', true)
            ->assertJsonPath('sham_cash_qr.content_base64', null);

        Http::assertSent(function (Request $httpRequest): bool {
            if (! str_contains($httpRequest->url(), 'botclient-token/sendPhoto')) {
                return false;
            }

            $payload = $httpRequest->data();
            $caption = (string) ($payload['caption'] ?? $httpRequest->body());

            return str_contains($caption, 'USD');
        });
    }

    public function test_approving_quotation_without_amount_does_not_send_payment_instructions(): void
    {
        Storage::fake('local');
        config(['services.telegram.bot_token' => 'client-token']);
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $path = UploadedFile::fake()->image('sham-cash.png')->store('payment', 'local');
        OpsSetting::setValue('sham_cash_qr_path', $path);

        $client = Client::factory()->create(['telegram_user_id' => 'tg-no-amount']);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::QuotationSent,
            'quotation_amount' => null,
            'amount_total' => null,
        ]);
        Quotation::query()->create([
            'request_id' => $request->id,
            'version' => 1,
            'amount' => 0,
            'sent_at' => now(),
        ]);

        $response = $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$request->number}/approve", [
                'telegram_user_id' => 'tg-no-amount',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonPath('sham_cash_qr.qr_available', false)
            ->assertJsonPath('sham_cash_qr.delivered', true)
            ->assertJsonPath('sham_cash_qr.content_base64', null);

        $caption = (string) $response->json('sham_cash_qr.caption');
        $this->assertStringContainsString('سيصلك المبلغ المطلوب', $caption);
        $this->assertStringNotContainsString('تعليمات الدفع', $caption);
        $this->assertStringNotContainsString('شام كاش', $caption);
        $this->assertStringNotContainsString('الإجمالي:', $caption);

        Http::assertSent(function (Request $httpRequest): bool {
            if (! str_contains($httpRequest->url(), 'botclient-token/sendMessage')) {
                return false;
            }

            $text = (string) ($httpRequest['text'] ?? $httpRequest->body());

            return str_contains($text, 'سيصلك المبلغ المطلوب')
                && ! str_contains($text, 'تعليمات الدفع')
                && ! str_contains($text, 'شام كاش');
        });
        Http::assertNotSent(fn (Request $httpRequest) => str_contains($httpRequest->url(), 'sendPhoto'));
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

    public function test_drive_folder_uses_company_name_not_telegram_id(): void
    {
        $client = Client::factory()->create([
            'telegram_user_id' => 'tg-flow',
            'name' => 'Client tg-flow',
            'company_name' => 'شركة النور',
        ]);
        $request = ServiceRequest::factory()->for($client)->create([
            'title' => 'هوية بصرية',
            'google_drive_folder_id' => null,
        ]);

        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('ensureFolderPath')
                ->once()
                ->withArgs(function (string $parent, array $segments): bool {
                    return ($segments[0] ?? '') === 'شركة النور'
                        && ! str_contains((string) $segments[0], 'tg-flow')
                        && ($segments[1] ?? '') === 'طلب يدوي'
                        && str_contains((string) ($segments[2] ?? ''), 'هوية بصرية');
                })
                ->andReturn('folder-company');
        });

        $updated = app(EnsureRequestDriveFolder::class)->handle($request);

        $this->assertSame('folder-company', $updated->google_drive_folder_id);
    }

    public function test_drive_folder_nests_company_package_and_request(): void
    {
        $package = $this->seedPublishedPackage();
        $client = Client::factory()->create(['company_name' => 'شركة النور']);
        $request = ServiceRequest::factory()->for($client)->create([
            'title' => 'هوية بصرية',
            'pricing_package_id' => $package->id,
            'billing_period' => 'monthly',
            'paid_at' => now()->setTimezone('Asia/Damascus')->setDate(2026, 9, 20)->setTime(12, 0),
            'google_drive_folder_id' => null,
        ]);

        $this->mock(GoogleDriveClient::class, function ($mock) use ($request): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('ensureFolderPath')
                ->once()
                ->withArgs(function (string $parent, array $segments) use ($request): bool {
                    $ref = ResolveServiceRequest::displayNumber($request);

                    return ($segments[0] ?? '') === 'شركة النور'
                        && ($segments[1] ?? '') === 'Startup — شهري'
                        && str_starts_with((string) ($segments[2] ?? ''), '#'.$ref.' هوية بصرية');
                })
                ->andReturn('folder-leaf');
        });

        $updated = app(EnsureRequestDriveFolder::class)->handle($request);

        $this->assertSame('folder-leaf', $updated->google_drive_folder_id);
    }

    public function test_remaining_payment_creates_drive_folder_when_missing(): void
    {
        Http::fake();
        config(['services.gemini.e2e_stub' => true]);

        $client = Client::factory()->create(['company_name' => 'شركة النور']);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::PaymentConfirmed,
            'title' => 'هوية بصرية',
            'quotation_amount' => 1000,
            'amount_total' => 1000,
            'amount_paid' => 400,
            'amount_remaining' => 600,
            'paid_at' => now()->subDay(),
            'google_drive_folder_id' => null,
        ]);

        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('ensureFolderPath')->once()->andReturn('folder-retry');
        });

        app(ConfirmRequestPayment::class)->handle($request, PaymentMethod::Receipt, 600);

        $this->assertSame('folder-retry', $request->fresh()?->google_drive_folder_id);
    }

    public function test_drive_poll_creates_missing_folder_for_paid_request(): void
    {
        Cache::flush();
        $client = Client::factory()->create(['company_name' => 'شركة النور']);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::PaymentConfirmed,
            'paid_at' => now(),
            'google_drive_folder_id' => null,
            'title' => 'هوية',
        ]);

        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('ensureFolderPath')->once()->andReturn('folder-poll');
            $mock->shouldReceive('isHocClientRootId')->andReturn(false);
            $mock->shouldReceive('listNewFiles')->with('folder-poll')->andReturn([]);
        });

        $this->artisan('ops:poll-drive')->assertSuccessful();

        $this->assertSame('folder-poll', $request->fresh()?->google_drive_folder_id);
    }

    public function test_drive_poll_skips_files_dropped_in_hoc_client_root(): void
    {
        Cache::flush();
        config(['services.google.drive_parent_folder_id' => 'root-hoc']);

        $client = Client::factory()->create(['telegram_user_id' => 'tg-root']);
        ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::InProgress,
            'google_drive_folder_id' => 'req-folder',
        ]);

        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('fileMeta')->with('root-file')->andReturn([
                'id' => 'root-file',
                'name' => 'loose.png',
                'mimeType' => 'image/png',
                'parents' => ['root-hoc'],
            ]);
            $mock->shouldReceive('isUnderParentFolder')->andReturn(true);
            $mock->shouldReceive('isDirectlyInHocClientRoot')->andReturn(true);
            $mock->shouldReceive('downloadFile')->never();
        });

        $this->artisan('ops:poll-drive', ['--file' => 'root-file'])->assertSuccessful();

        $this->assertDatabaseMissing('drive_deliveries', ['drive_file_id' => 'root-file']);
    }

    public function test_drive_poll_does_not_resend_an_unchanged_file(): void
    {
        Cache::flush();
        config(['services.telegram.bot_token' => 'client-token']);
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 77],
            ], 200),
        ]);

        $client = Client::factory()->create(['telegram_user_id' => 'tg-once']);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::InProgress,
            'google_drive_folder_id' => 'folder-once',
        ]);
        $modified = now()->startOfSecond();
        DriveDelivery::query()->create([
            'request_id' => $request->id,
            'drive_file_id' => 'file-once',
            'name' => 'logo.png',
            'mime_type' => 'image/png',
            'content_hash' => md5('PNG'),
            'drive_modified_at' => $modified,
            'sent_at' => now()->subMinute(),
        ]);

        $this->mock(GoogleDriveClient::class, function ($mock) use ($modified): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('isHocClientRootId')->andReturn(false);
            $mock->shouldReceive('listNewFiles')->andReturn([
                [
                    'id' => 'file-once',
                    'name' => 'logo.png',
                    'mimeType' => 'image/png',
                    'modifiedTime' => $modified->copy()->micro(750000)->toIso8601String(),
                    'md5Checksum' => 'not-the-local-md5',
                ],
            ]);
            $mock->shouldReceive('downloadFile')->never();
        });

        $this->artisan('ops:poll-drive')->assertSuccessful();

        Http::assertNotSent(fn (Request $httpRequest): bool => str_contains($httpRequest->url(), 'api.telegram.org'));
    }

    public function test_drive_folder_ignores_telegram_placeholder_company(): void
    {
        $client = Client::factory()->create([
            'telegram_user_id' => 'tg-flow',
            'name' => 'Client tg-flow',
            'company_name' => 'شركةtg-flow',
        ]);
        $request = ServiceRequest::factory()->for($client)->create([
            'google_drive_folder_id' => null,
        ]);

        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('ensureFolderPath')
                ->once()
                ->withArgs(function (string $parent, array $segments): bool {
                    return ($segments[0] ?? '') === 'شركة' && ! str_contains(implode('/', $segments), 'tg-flow');
                })
                ->andReturn('folder-generic');
        });

        app(EnsureRequestDriveFolder::class)->handle($request);
    }

    public function test_drive_poll_retries_until_telegram_send_succeeds(): void
    {
        Cache::flush();
        Storage::fake('local');
        config(['services.telegram.bot_token' => null]);

        $client = Client::factory()->create(['telegram_user_id' => 'tg-drive']);
        ServiceRequest::factory()->create([
            'client_id' => $client->id,
            'status' => RequestStatus::PaymentConfirmed,
            'google_drive_folder_id' => 'folder-1',
        ]);

        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('isHocClientRootId')->andReturn(false);
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
        Http::assertSent(fn (Request $httpRequest): bool => str_contains($httpRequest->url(), 'botclient-token/sendPhoto'));
    }

    public function test_client_can_request_revision_on_drive_file_and_see_status_in_my_requests(): void
    {
        $client = Client::factory()->create(['telegram_user_id' => 'tg-revfile']);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::InProgress,
            'title' => 'شعار',
        ]);
        $delivery = DriveDelivery::query()->create([
            'request_id' => $request->id,
            'drive_file_id' => 'file-logo',
            'name' => 'logo.png',
            'mime_type' => 'image/png',
            'sent_at' => now(),
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->getJson('/api/bot/telegram/requests?telegram_user_id=tg-revfile')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'in_progress')
            ->assertJsonPath('data.0.status_label', 'قيد التنفيذ')
            ->assertJsonPath('data.0.can_revise', true)
            ->assertJsonPath('data.0.can_complete', false)
            ->assertJsonPath('data.0.display_number', ResolveServiceRequest::displayNumber($request));

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$request->number}/revision", [
                'telegram_user_id' => 'tg-revfile',
                'reason' => 'غطيّر اللون',
                'drive_delivery_id' => $delivery->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'revision_requested');

        $revision = Revision::query()->where('request_id', $request->id)->latest('id')->first();
        $this->assertNotNull($revision);
        $this->assertStringContainsString('logo.png', (string) $revision->comments);
        $this->assertStringContainsString('غطيّر اللون', (string) $revision->comments);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$request->number}/revision", [
                'telegram_user_id' => 'tg-revfile',
                'reason' => 'أعد كل الملفات',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'revision_requested');

        $this->assertSame(2, Revision::query()->where('request_id', $request->id)->count());
        $this->assertStringContainsString(
            'الطلب بالكامل',
            (string) Revision::query()->where('request_id', $request->id)->latest('id')->value('comments'),
        );

        $keyboard = $delivery->clientRevisionKeyboard('12');
        $this->assertSame('revfile:12:'.$delivery->id, $keyboard['inline_keyboard'][0][0]['callback_data']);
        $this->assertSame('okfile:12:'.$delivery->id, $keyboard['inline_keyboard'][1][0]['callback_data']);
        $this->assertSame('revision:12', $keyboard['inline_keyboard'][2][0]['callback_data']);
        $this->assertSame('complete:12', $keyboard['inline_keyboard'][3][0]['callback_data']);
    }

    public function test_revision_sends_the_image_and_reason_to_staff(): void
    {
        Storage::fake('local');
        config(['services.telegram.staff_bot_token' => 'staff-token']);
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['photo' => [['file_id' => 'staff-photo']]],
            ], 200),
        ]);

        Employee::factory()->create([
            'profession' => EmployeeProfession::Sales,
            'status' => EmployeeStatus::Approved,
            'telegram_user_id' => '555',
        ]);
        $client = Client::factory()->create(['telegram_user_id' => 'tg-rev-photo']);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::InProgress,
            'title' => 'شعار',
        ]);
        $delivery = DriveDelivery::query()->create([
            'request_id' => $request->id,
            'drive_file_id' => 'file-logo',
            'name' => 'logo.png',
            'mime_type' => 'image/png',
            'sent_at' => now(),
        ]);

        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('downloadFile')->with('file-logo')->andReturn('PNG');
        });

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$request->number}/revision", [
                'telegram_user_id' => 'tg-rev-photo',
                'reason' => 'غطيّر اللون',
                'drive_delivery_id' => $delivery->id,
            ])
            ->assertOk();

        Http::assertSent(function (Request $httpRequest): bool {
            $body = $httpRequest->body();

            return str_contains($httpRequest->url(), 'botstaff-token/sendPhoto')
                && str_contains($body, 'logo.png')
                && str_contains($body, 'غطيّر اللون');
        });
    }

    public function test_client_can_approve_a_delivered_file_and_staff_cannot_complete_before_that(): void
    {
        Storage::fake('local');
        config(['services.telegram.staff_bot_token' => 'staff-token']);
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['photo' => [['file_id' => 'staff-photo']]],
            ], 200),
        ]);

        Employee::factory()->sales()->create([
            'status' => EmployeeStatus::Approved,
            'telegram_user_id' => '6350001',
        ]);
        $client = Client::factory()->create(['telegram_user_id' => 'tg-okfile']);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::ReadyForReview,
            'title' => 'هوية',
        ]);
        $delivery = DriveDelivery::query()->create([
            'request_id' => $request->id,
            'drive_file_id' => 'file-ok',
            'name' => 'cover.png',
            'mime_type' => 'image/png',
            'sent_at' => now(),
        ]);

        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('downloadFile')->with('file-ok')->andReturn('PNG');
        });

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->postJson('/api/bot/staff/complete', [
                'telegram_user_id' => '6350001',
                'request_number' => $request->number,
            ])
            ->assertUnprocessable();

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$request->number}/approve-file", [
                'telegram_user_id' => 'tg-okfile',
                'drive_delivery_id' => $delivery->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.approved', true);

        $this->assertNotNull($delivery->fresh()?->client_approved_at);
        Http::assertSent(function (Request $httpRequest): bool {
            $body = $httpRequest->body();

            return str_contains($httpRequest->url(), 'botstaff-token/sendPhoto')
                && str_contains($body, 'وافق الزبون على هذه الصورة')
                && str_contains($body, 'cover.png');
        });

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-staff'])
            ->postJson('/api/bot/staff/complete', [
                'telegram_user_id' => '6350001',
                'request_number' => $request->number,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_drive_file_opens_request_for_client_review(): void
    {
        Cache::flush();
        Storage::fake('local');
        config(['services.telegram.bot_token' => 'client-token']);
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['photo' => [['file_id' => 'tg-photo']]],
            ], 200),
        ]);

        $client = Client::factory()->create(['telegram_user_id' => 'tg-drive-ready']);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::PaymentConfirmed,
            'google_drive_folder_id' => 'folder-ready',
            'paid_at' => now(),
        ]);

        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('isHocClientRootId')->andReturn(false);
            $mock->shouldReceive('listNewFiles')->andReturn([
                ['id' => 'file-ready', 'name' => 'final.png', 'mimeType' => 'image/png'],
            ]);
            $mock->shouldReceive('downloadFile')->with('file-ready')->andReturn('PNG');
        });

        $this->artisan('ops:poll-drive')->assertSuccessful();

        $this->assertSame(RequestStatus::InProgress, $request->fresh()?->status);
        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->getJson('/api/bot/telegram/requests?telegram_user_id=tg-drive-ready')
            ->assertOk()
            ->assertJsonPath('data.0.can_complete', false)
            ->assertJsonPath('data.0.can_revise', true);

        $this->travel(3)->minutes();
        $this->artisan('ops:poll-drive')->assertSuccessful();

        $this->assertSame(RequestStatus::ReadyForReview, $request->fresh()?->status);
        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->getJson('/api/bot/telegram/requests?telegram_user_id=tg-drive-ready')
            ->assertOk()
            ->assertJsonPath('data.0.can_complete', true)
            ->assertJsonPath('data.0.can_revise', true);
    }

    public function test_revision_is_blocked_before_drive_files_or_delivery(): void
    {
        $client = Client::factory()->create(['telegram_user_id' => 'tg-revblock']);
        $paid = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::PaymentConfirmed,
        ]);
        $progress = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::InProgress,
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->getJson('/api/bot/telegram/requests?telegram_user_id=tg-revblock')
            ->assertOk()
            ->assertJsonPath('data.0.can_revise', false)
            ->assertJsonPath('data.1.can_revise', false);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$paid->number}/revision", [
                'telegram_user_id' => 'tg-revblock',
                'reason' => 'بكّر',
            ])
            ->assertUnprocessable();

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$progress->number}/revision", [
                'telegram_user_id' => 'tg-revblock',
                'reason' => 'بكّر',
            ])
            ->assertUnprocessable();
    }

    public function test_client_can_revise_payment_confirmed_request_after_drive_file_exists(): void
    {
        $client = Client::factory()->create(['telegram_user_id' => 'tg-revpaid']);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::PaymentConfirmed,
            'title' => 'هوية',
        ]);
        DriveDelivery::query()->create([
            'request_id' => $request->id,
            'drive_file_id' => 'file-paid',
            'name' => 'mark.jpg',
            'mime_type' => 'image/jpeg',
            'sent_at' => now(),
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->getJson('/api/bot/telegram/requests?telegram_user_id=tg-revpaid')
            ->assertOk()
            ->assertJsonPath('data.0.can_revise', true);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$request->number}/revision", [
                'telegram_user_id' => 'tg-revpaid',
                'reason' => 'كبّر الشعار',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'revision_requested');
    }

    public function test_receipt_is_rejected_before_awaiting_payment(): void
    {
        $client = Client::factory()->create(['telegram_user_id' => 'tg-receipt-gate']);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::QuotationSent,
            'amount_remaining' => 400,
        ]);

        $this->assertFalse($request->acceptsReceiptUpload());

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$request->number}/receipt", [
                'telegram_user_id' => 'tg-receipt-gate',
                'file_name' => 'receipt.jpg',
                'file_base64' => base64_encode('img'),
                'mime_type' => 'image/jpeg',
            ])
            ->assertStatus(422);
    }

    public function test_drive_file_replace_is_resent_to_client(): void
    {
        Cache::flush();
        Storage::fake('local');
        config(['services.telegram.bot_token' => 'client-token']);
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 88, 'photo' => [['file_id' => 'tg-photo']]],
            ], 200),
        ]);

        $client = Client::factory()->create(['telegram_user_id' => 'tg-drive-replace']);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::RevisionRequested,
            'google_drive_folder_id' => 'folder-replace',
        ]);
        DriveDelivery::query()->create([
            'request_id' => $request->id,
            'drive_file_id' => 'file-same',
            'name' => 'logo.png',
            'mime_type' => 'image/png',
            'content_hash' => md5('OLD'),
            'drive_modified_at' => now()->subHour(),
            'sent_at' => now()->subHour(),
        ]);

        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('isHocClientRootId')->andReturn(false);
            $mock->shouldReceive('listNewFiles')->andReturn([
                [
                    'id' => 'file-same',
                    'name' => 'logo.png',
                    'mimeType' => 'image/png',
                    'modifiedTime' => now()->toIso8601String(),
                    'md5Checksum' => md5('NEW'),
                ],
            ]);
            $mock->shouldReceive('downloadFile')->with('file-same')->andReturn('NEW');
        });

        $this->artisan('ops:poll-drive')->assertSuccessful();

        $delivery = DriveDelivery::query()->where('drive_file_id', 'file-same')->first();
        $this->assertNotNull($delivery?->sent_at);
        $this->assertSame(md5('NEW'), $delivery?->content_hash);
        Http::assertSent(function (Request $httpRequest): bool {
            $body = $httpRequest->body();

            return str_contains($httpRequest->url(), 'botclient-token/sendPhoto')
                && str_contains($body, 'تم تعديل الملف')
                && str_contains($body, 'logo.png');
        });
    }

    public function test_drive_upload_with_same_name_updates_previous_file_instead_of_creating_new(): void
    {
        Cache::flush();
        Storage::fake('local');
        config(['services.telegram.bot_token' => 'client-token']);
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 89, 'photo' => [['file_id' => 'tg-photo']]],
            ], 200),
        ]);

        $client = Client::factory()->create(['telegram_user_id' => 'tg-drive-same-name']);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::RevisionRequested,
            'google_drive_folder_id' => 'folder-same-name',
        ]);
        $previous = DriveDelivery::query()->create([
            'request_id' => $request->id,
            'drive_file_id' => 'file-old',
            'name' => 'Logo.PNG',
            'mime_type' => 'image/png',
            'content_hash' => md5('OLD'),
            'drive_modified_at' => now()->subHour(),
            'sent_at' => now()->subHour(),
            'client_approved_at' => now()->subMinutes(30),
        ]);

        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('isHocClientRootId')->andReturn(false);
            $mock->shouldReceive('listNewFiles')->andReturn([
                [
                    'id' => 'file-new-id',
                    'name' => 'logo.png',
                    'mimeType' => 'image/png',
                    'modifiedTime' => now()->toIso8601String(),
                    'md5Checksum' => md5('NEW'),
                ],
            ]);
            $mock->shouldReceive('downloadFile')->with('file-new-id')->andReturn('NEW');
        });

        $this->artisan('ops:poll-drive')->assertSuccessful();

        $this->assertSame(1, DriveDelivery::query()->where('request_id', $request->id)->count());
        $updated = $previous->fresh();
        $this->assertSame('file-new-id', $updated?->drive_file_id);
        $this->assertSame('logo.png', $updated?->name);
        $this->assertSame(md5('NEW'), $updated?->content_hash);
        $this->assertNull($updated?->client_approved_at);
        $this->assertNotNull($updated?->sent_at);
        Http::assertSent(function (Request $httpRequest): bool {
            $body = $httpRequest->body();

            return str_contains($httpRequest->url(), 'botclient-token/sendPhoto')
                && str_contains($body, 'تم تعديل الملف')
                && str_contains($body, 'logo.png');
        });
    }

    public function test_drive_upload_with_unknown_name_is_a_new_file(): void
    {
        Cache::flush();
        Storage::fake('local');
        config(['services.telegram.bot_token' => 'client-token']);
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 90, 'photo' => [['file_id' => 'tg-photo']]],
            ], 200),
        ]);

        $client = Client::factory()->create(['telegram_user_id' => 'tg-drive-new-name']);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::InProgress,
            'google_drive_folder_id' => 'folder-new-name',
        ]);
        DriveDelivery::query()->create([
            'request_id' => $request->id,
            'drive_file_id' => 'file-logo',
            'name' => 'logo.png',
            'mime_type' => 'image/png',
            'content_hash' => md5('LOGO'),
            'sent_at' => now()->subHour(),
        ]);

        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('isHocClientRootId')->andReturn(false);
            $mock->shouldReceive('listNewFiles')->andReturn([
                [
                    'id' => 'file-card',
                    'name' => 'card.png',
                    'mimeType' => 'image/png',
                    'modifiedTime' => now()->toIso8601String(),
                ],
            ]);
            $mock->shouldReceive('downloadFile')->with('file-card')->andReturn('CARD');
        });

        $this->artisan('ops:poll-drive')->assertSuccessful();

        $this->assertSame(2, DriveDelivery::query()->where('request_id', $request->id)->count());
        $this->assertNotNull(DriveDelivery::query()->where('drive_file_id', 'file-card')->value('sent_at'));
        Http::assertSent(function (Request $httpRequest): bool {
            $body = $httpRequest->body();

            return str_contains($httpRequest->url(), 'botclient-token/sendPhoto')
                && str_contains($body, 'ملف جديد')
                && str_contains($body, 'card.png')
                && ! str_contains($body, 'تم تعديل الملف');
        });
    }

    public function test_drive_file_option_sends_that_file_immediately(): void
    {
        Cache::flush();
        Storage::fake('local');
        config(['services.telegram.bot_token' => 'client-token']);
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 91, 'photo' => [['file_id' => 'tg-photo']]],
            ], 200),
        ]);

        $client = Client::factory()->create(['telegram_user_id' => 'tg-drive-now']);
        ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::InProgress,
            'google_drive_folder_id' => 'folder-now',
        ]);

        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('fileMeta')->with('file-now')->andReturn([
                'id' => 'file-now',
                'name' => 'cover.png',
                'mimeType' => 'image/png',
                'parents' => ['folder-now'],
            ]);
            $mock->shouldReceive('isUnderParentFolder')->andReturn(true);
            $mock->shouldReceive('isDirectlyInHocClientRoot')->andReturn(false);
            $mock->shouldReceive('isHocClientRootId')->andReturn(false);
            $mock->shouldReceive('listNewFiles')->never();
            $mock->shouldReceive('downloadFile')->with('file-now')->andReturn('PNG');
        });

        $this->artisan('ops:poll-drive', ['--file' => 'file-now'])->assertSuccessful();

        $this->assertNotNull(DriveDelivery::query()->where('drive_file_id', 'file-now')->value('sent_at'));
        Http::assertSent(fn (Request $httpRequest): bool => str_contains($httpRequest->url(), 'botclient-token/sendPhoto'));
    }

    public function test_drive_file_option_skips_files_outside_hoc_client(): void
    {
        Cache::flush();
        config(['services.telegram.bot_token' => 'client-token']);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $client = Client::factory()->create(['telegram_user_id' => 'tg-drive-skip']);
        ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::InProgress,
            'google_drive_folder_id' => 'folder-now',
        ]);

        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('fileMeta')->with('file-other')->andReturn([
                'id' => 'file-other',
                'name' => 'other.png',
                'mimeType' => 'image/png',
                'parents' => ['unrelated-folder'],
            ]);
            $mock->shouldReceive('isUnderParentFolder')->andReturn(false);
            $mock->shouldReceive('downloadFile')->never();
        });

        $this->artisan('ops:poll-drive', ['--file' => 'file-other'])->assertSuccessful();

        $this->assertNull(DriveDelivery::query()->where('drive_file_id', 'file-other')->value('sent_at'));
        Http::assertNothingSent();
    }

    public function test_drive_poll_skips_completed_folders(): void
    {
        Cache::flush();
        $client = Client::factory()->create(['telegram_user_id' => 'tg-done']);
        ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::Completed,
            'google_drive_folder_id' => 'folder-done',
        ]);

        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('isHocClientRootId')->andReturn(false);
            $mock->shouldReceive('listNewFiles')->never();
        });

        $this->artisan('ops:poll-drive')->assertSuccessful();
    }

    public function test_support_notifies_sales_staff(): void
    {
        config(['services.telegram.staff_bot_token' => 'staff-token']);
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->completeClient('tg-support-alert');
        Employee::factory()->create([
            'profession' => EmployeeProfession::Sales,
            'status' => EmployeeStatus::Approved,
            'telegram_user_id' => '555',
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson('/api/bot/telegram/support', [
                'telegram_user_id' => 'tg-support-alert',
                'message' => 'متى يبدأ العمل؟',
            ])
            ->assertOk();

        Http::assertSent(fn (Request $httpRequest): bool => str_contains($httpRequest->url(), 'botstaff-token/sendMessage')
            && str_contains((string) ($httpRequest['text'] ?? ''), 'متى يبدأ العمل؟'));
    }

    public function test_can_renew_only_after_completed(): void
    {
        $client = Client::factory()->create(['telegram_user_id' => 'tg-renew-gate']);
        ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::InProgress,
            'allows_renewal' => true,
        ]);
        $done = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::Completed,
            'allows_renewal' => true,
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->getJson('/api/bot/telegram/requests?telegram_user_id=tg-renew-gate')
            ->assertOk()
            ->assertJsonPath('data.0.can_renew', true)
            ->assertJsonPath('data.1.can_renew', false);

        $this->assertTrue($done->canRenew());
    }

    public function test_catalog_request_is_not_duplicated_within_three_minutes(): void
    {
        $package = $this->seedPublishedPackage();
        $this->completeClient('tg-catalog-once');

        $first = $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson('/api/bot/telegram/catalog/requests', [
                'telegram_user_id' => 'tg-catalog-once',
                'package_id' => $package->id,
                'billing_period' => 'monthly',
            ])->assertCreated()
            ->json('data');

        $second = $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson('/api/bot/telegram/catalog/requests', [
                'telegram_user_id' => 'tg-catalog-once',
                'package_id' => $package->id,
                'billing_period' => 'monthly',
            ])->assertCreated()
            ->json('data');

        $this->assertSame($first['number'], $second['number']);
        $this->assertSame(1, ServiceRequest::query()->where('number', $first['number'])->count());
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
