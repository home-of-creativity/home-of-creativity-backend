<?php

namespace Tests\Feature;

use App\Actions\ImportOdooCrmClients;
use App\Enums\EmployeeProfession;
use App\Enums\RequestStatus;
use App\Enums\SocialPostStatus;
use App\Jobs\ClassifyWithGeminiJob;
use App\Models\Client;
use App\Models\Employee;
use App\Models\ServiceRequest;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\GoogleDriveClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesOdooDocuments;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use FakesOdooDocuments;
    use RefreshDatabase;

    public function test_client_cannot_open_admin_overview(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/overview')->assertForbidden();
    }

    public function test_admin_can_open_overview(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/overview')
            ->assertOk()
            ->assertJsonPath('data.requests', 0)
            ->assertJsonPath('data.pending_employees', 0)
            ->assertJsonPath('data.recent', []);
    }

    public function test_admin_can_open_live_feed(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $request = ServiceRequest::factory()->create([
            'status' => RequestStatus::Submitted,
        ]);
        $post = SocialPost::factory()->recycle($admin)->create([
            'created_by' => $admin->id,
            'status' => SocialPostStatus::Published,
            'body' => 'Live studio check.',
        ]);

        $this->getJson('/api/admin/live')
            ->assertOk()
            ->assertJsonPath('data.requests_count', 1)
            ->assertJsonPath('data.publishing', 0)
            ->assertJsonPath('data.requests.0.id', $request->id)
            ->assertJsonPath('data.requests.0.status', RequestStatus::Submitted->value)
            ->assertJsonPath('data.posts.0.id', $post->id)
            ->assertJsonPath('data.posts.0.status', SocialPostStatus::Published->value)
            ->assertJsonPath('data.posts.0.body', 'Live studio check.');
    }

    public function test_client_cannot_open_live_feed(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/live')->assertForbidden();
    }

    public function test_admin_confirm_payment_queues_gemini(): void
    {
        Http::fake();
        Bus::fake([ClassifyWithGeminiJob::class]);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $serviceRequest = ServiceRequest::factory()->create([
            'status' => RequestStatus::AwaitingPayment,
        ]);

        $this->postJson("/api/admin/requests/{$serviceRequest->id}/confirm-payment", [
            'payment_method' => 'cash',
            'amount' => 100,
        ])->assertOk()
            ->assertJsonPath('data.status', 'payment_confirmed')
            ->assertJsonPath('data.gemini_status', 'pending');

        $this->assertSame(RequestStatus::PaymentConfirmed, $serviceRequest->fresh()->status);
        Bus::assertDispatched(ClassifyWithGeminiJob::class);
    }

    public function test_admin_confirm_payment_requires_received_amount(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $serviceRequest = ServiceRequest::factory()->create([
            'status' => RequestStatus::AwaitingPayment,
        ]);

        $this->postJson("/api/admin/requests/{$serviceRequest->id}/confirm-payment", [
            'payment_method' => 'cash',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_admin_cannot_confirm_payment_before_quotation_approval(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $serviceRequest = ServiceRequest::factory()->create([
            'status' => RequestStatus::Submitted,
        ]);

        $this->postJson("/api/admin/requests/{$serviceRequest->id}/confirm-payment", [
            'payment_method' => 'cash',
            'amount' => 100,
        ])->assertUnprocessable();

        $this->patchJson("/api/admin/requests/{$serviceRequest->id}", [
            'status' => 'payment_confirmed',
        ])->assertUnprocessable();

        $this->assertSame('submitted', $serviceRequest->fresh()?->status->value);
    }

    public function test_admin_quotation_partial_payment_sets_first_due_from_line_quantity(): void
    {
        Http::fake();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $client = Client::factory()->create(['telegram_user_id' => 'tg-partial-qty']);
        $serviceRequest = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::Submitted,
        ]);

        $this->postJson("/api/admin/requests/{$serviceRequest->id}/quotation", [
            'lines' => [
                ['title' => 'تصميم', 'amount' => 200, 'units' => 2],
            ],
            'requires_full_payment' => false,
        ])->assertOk()
            ->assertJsonPath('data.requires_full_payment', false)
            ->assertJsonPath('data.payment_plan', 'partial');

        $this->assertEquals(400, (float) $serviceRequest->fresh()?->quotation_amount);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$serviceRequest->number}/approve", [
                'telegram_user_id' => 'tg-partial-qty',
            ])
            ->assertOk()
            ->assertJsonPath('data.requires_full_payment', false)
            ->assertJsonPath('data.payment_plan', 'partial');

        $approved = $serviceRequest->fresh();
        $this->assertNotNull($approved);
        $this->assertEqualsWithDelta(400, (float) $approved->amount_total, 0.01);
        $this->assertEqualsWithDelta(200, $approved->expectedDue(), 0.01);
    }

    public function test_admin_quotation_full_payment_sets_full_due_amount(): void
    {
        Http::fake();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $client = Client::factory()->create(['telegram_user_id' => 'tg-full-qty']);
        $serviceRequest = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::Submitted,
        ]);

        $this->postJson("/api/admin/requests/{$serviceRequest->id}/quotation", [
            'lines' => [
                ['title' => 'حملة', 'amount' => 150, 'units' => 2],
            ],
            'requires_full_payment' => true,
        ])->assertOk()
            ->assertJsonPath('data.requires_full_payment', true)
            ->assertJsonPath('data.payment_plan', 'full');

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson("/api/bot/telegram/requests/{$serviceRequest->number}/approve", [
                'telegram_user_id' => 'tg-full-qty',
            ])
            ->assertOk();

        $approved = $serviceRequest->fresh();
        $this->assertNotNull($approved);
        $this->assertEqualsWithDelta(300, $approved->expectedDue(), 0.01);
    }

    public function test_admin_requests_are_paginated(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        ServiceRequest::factory()->count(21)->create();

        $this->getJson('/api/admin/requests?per_page=20')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 21)
            ->assertJsonPath('meta.per_page', 20);

        $this->getJson('/api/admin/requests?page=2&per_page=20')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_admin_clients_are_paginated(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        Client::factory()->count(21)->create();

        $this->getJson('/api/admin/clients?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.total', 21);

        $this->getJson('/api/admin/clients?page=3&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 3);
    }

    public function test_incomplete_telegram_clients_are_hidden_until_profile_complete(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson('/api/bot/telegram/link', [
                'telegram_user_id' => 'tg-hidden',
                'name' => 'Ali',
                'locale' => 'ar',
            ])->assertOk()
            ->assertJsonPath('data.profile_complete', false);

        $stub = Client::query()->where('telegram_user_id', 'tg-hidden')->firstOrFail();

        $this->getJson('/api/admin/clients')
            ->assertOk()
            ->assertJsonMissing(['id' => $stub->id]);

        $this->getJson('/api/admin/overview')
            ->assertOk()
            ->assertJsonPath('data.clients', 0);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson('/api/bot/telegram/profile', [
                'telegram_user_id' => 'tg-hidden',
                'phone' => '+963911111111',
                'company_name' => 'شركة علي',
            ])->assertOk()
            ->assertJsonPath('data.profile_complete', true);

        $this->getJson('/api/admin/clients')
            ->assertOk()
            ->assertJsonFragment(['id' => $stub->id, 'telegram_user_id' => 'tg-hidden']);

        $this->getJson('/api/admin/overview')
            ->assertOk()
            ->assertJsonPath('data.clients', 1);
    }

    public function test_admin_can_create_client_and_push_to_odoo(): void
    {
        Http::preventStrayRequests();
        $this->fakeOdooDocuments();
        Http::fake($this->odooDocumentsHttpFake());

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/clients', [
            'name' => 'Studio Client',
            'email' => 'studio@hoc.test',
            'phone' => '+963999000111',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Studio Client')
            ->assertJsonPath('data.odoo_partner_id', '44')
            ->assertJsonPath('data.odoo_lead_id', '77');

        $this->assertDatabaseHas('clients', [
            'email' => 'studio@hoc.test',
            'odoo_partner_id' => '44',
            'odoo_lead_id' => '77',
        ]);
    }

    public function test_admin_update_client_writes_odoo_partner_and_lead(): void
    {
        Http::preventStrayRequests();
        $this->fakeOdooDocuments();
        Http::fake($this->odooDocumentsHttpFake());

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $client = Client::factory()->create([
            'name' => 'Old Name',
            'company_name' => 'Old Co',
            'email' => 'old@hoc.test',
            'phone' => '+963100',
            'odoo_partner_id' => '44',
            'odoo_lead_id' => '77',
        ]);

        $this->putJson("/api/admin/clients/{$client->id}", [
            'name' => 'New Name',
            'company_name' => 'New Co',
            'email' => 'new@hoc.test',
            'phone' => '+963200',
        ])->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.company_name', 'New Co')
            ->assertJsonPath('data.odoo_partner_id', '44')
            ->assertJsonPath('data.odoo_lead_id', '77');

        Http::assertSent(function (Request $request): bool {
            $args = $request->data()['params']['args'] ?? [];

            return ($args[3] ?? null) === 'res.partner' && ($args[4] ?? null) === 'write';
        });
        Http::assertSent(function (Request $request): bool {
            $args = $request->data()['params']['args'] ?? [];
            if (($args[3] ?? null) !== 'crm.lead' || ($args[4] ?? null) !== 'write') {
                return false;
            }

            $vals = $args[5][1] ?? [];

            // A dashboard profile edit must never drag an opportunity that
            // progressed past تلغرام (e.g. تم الفوز بها) back to that stage,
            // and must always keep the تلغرام tag present (add-only command).
            return ! array_key_exists('stage_id', $vals)
                && ! array_key_exists('team_id', $vals)
                && ($vals['tag_ids'][0] ?? null) === [4, 3];
        });
    }

    public function test_admin_delete_client_unlinks_odoo_crm_records(): void
    {
        Http::preventStrayRequests();
        $this->fakeOdooDocuments();
        Http::fake($this->odooDocumentsHttpFake());

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $client = Client::factory()->create([
            'odoo_partner_id' => '44',
            'odoo_lead_id' => '77',
            'telegram_user_id' => null,
        ]);

        $this->deleteJson("/api/admin/clients/{$client->id}")
            ->assertOk();

        $this->assertSoftDeleted($client);
        Http::assertSent(function (Request $request): bool {
            $args = $request->data()['params']['args'] ?? [];

            return ($args[3] ?? null) === 'crm.lead' && ($args[4] ?? null) === 'unlink';
        });
        Http::assertSent(function (Request $request): bool {
            $args = $request->data()['params']['args'] ?? [];

            return ($args[3] ?? null) === 'res.partner' && ($args[4] ?? null) === 'unlink';
        });
    }

    public function test_admin_clients_index_lists_sql_without_live_odoo_pull(): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        $this->fakeOdooDocuments();
        Http::fake($this->odooDocumentsHttpFake());

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        Client::factory()->create([
            'name' => 'Local Only',
            'phone' => '+963900000000',
            'company_name' => 'Local Co',
        ]);

        $this->getJson('/api/admin/clients')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Local Only']);

        Http::assertNotSent(function (Request $request): bool {
            $args = $request->data()['params']['args'] ?? [];

            return ($args[3] ?? null) === 'crm.lead' && in_array($args[4] ?? null, ['search_read', 'create'], true);
        });
    }

    public function test_admin_clients_index_pushes_complete_telegram_clients_to_telegram_pipeline(): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        $this->fakeOdooDocuments();
        Http::fake($this->odooDocumentsHttpFake());

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        Client::factory()->create([
            'name' => 'AmmarHeroo',
            'company_name' => 'Prodesign',
            'phone' => '0950000700',
            'telegram_user_id' => '213309826',
            'odoo_partner_id' => null,
            'odoo_lead_id' => null,
            'odoo_stage_name' => null,
        ]);

        $this->getJson('/api/admin/clients')
            ->assertOk()
            ->assertJsonPath('data.0.odoo_lead_id', '77')
            ->assertJsonPath('data.0.odoo_stage_name', 'تلغرام');

        Http::assertSent(function (Request $request): bool {
            $args = $request->data()['params']['args'] ?? [];
            if (($args[3] ?? null) !== 'crm.lead' || ($args[4] ?? null) !== 'create') {
                return false;
            }

            $vals = $args[5][0][0] ?? [];

            return ($vals['stage_id'] ?? null) === 11
                && ($vals['team_id'] ?? null) === 21
                && ($vals['user_id'] ?? null) === 2
                && ($vals['type'] ?? null) === 'opportunity';
        });
    }

    public function test_admin_clients_index_pulls_live_odoo_crm_fields(): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        $this->fakeOdooDocuments();
        Http::fake($this->odooDocumentsHttpFake());

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        Client::factory()->create([
            'name' => 'Stale',
            'company_name' => 'Stale Co',
            'odoo_lead_id' => '77',
            'odoo_partner_id' => '44',
        ]);

        app(ImportOdooCrmClients::class)->handle(200);

        $this->getJson('/api/admin/clients')
            ->assertOk()
            ->assertJsonPath('data.0.email', 'sara@hoc.test')
            ->assertJsonPath('data.0.odoo_stage_name', 'تلغرام')
            ->assertJsonPath('data.0.odoo_live.stage', 'تلغرام');
    }

    public function test_admin_clients_index_imports_odoo_crm_without_a_sync_button(): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        $this->fakeOdooDocuments();
        Http::fake($this->odooDocumentsHttpFake());

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        app(ImportOdooCrmClients::class)->handle(200);

        $this->getJson('/api/admin/clients')
            ->assertOk()
            ->assertJsonFragment(['email' => 'sara@hoc.test']);

        $this->assertDatabaseHas('clients', [
            'email' => 'sara@hoc.test',
            'odoo_lead_id' => '77',
            'odoo_partner_id' => '44',
        ]);
    }

    public function test_clients_index_pushes_dashboard_clients_missing_odoo_leads(): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        $this->fakeOdooDocuments();
        Http::fake([
            'https://odoo.test/jsonrpc' => function (Request $request) {
                $params = $request->data()['params'] ?? [];
                if (($params['service'] ?? '') === 'common') {
                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2], 200);
                }

                $args = $params['args'] ?? [];
                $model = (string) ($args[3] ?? '');
                $action = (string) ($args[4] ?? '');

                if ($model === 'crm.lead' && $action === 'search_read') {
                    $domain = $args[5][0] ?? [];
                    foreach ($domain as $clause) {
                        if (($clause[0] ?? '') === 'id') {
                            return Http::response(['jsonrpc' => '2.0', 'result' => [[
                                'id' => 801,
                                'stage_id' => [11, 'تلغرام'],
                                'contact_name' => 'Prodesign',
                                'partner_name' => 'Prodesign',
                                'email_from' => false,
                                'phone' => '0957470371',
                                'partner_id' => [58, 'Prodesign'],
                            ]]], 200);
                        }
                    }

                    return Http::response(['jsonrpc' => '2.0', 'result' => []], 200);
                }

                if ($model === 'crm.lead' && $action === 'create') {
                    return Http::response(['jsonrpc' => '2.0', 'result' => 801], 200);
                }

                if ($model === 'res.partner' && $action === 'search_read') {
                    return Http::response(['jsonrpc' => '2.0', 'result' => []], 200);
                }

                if ($model === 'res.partner' && $action === 'search') {
                    return Http::response(['jsonrpc' => '2.0', 'result' => []], 200);
                }

                if ($model === 'crm.stage' && $action === 'search_read') {
                    return Http::response(['jsonrpc' => '2.0', 'result' => [
                        ['id' => 11, 'name' => 'تلغرام'],
                    ]], 200);
                }

                if ($model === 'crm.team' && $action === 'search_read') {
                    return Http::response(['jsonrpc' => '2.0', 'result' => [
                        ['id' => 21, 'name' => 'تلغرام'],
                    ]], 200);
                }

                return Http::response(['jsonrpc' => '2.0', 'result' => true], 200);
            },
        ]);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        Client::factory()->create([
            'name' => 'Prodesign',
            'company_name' => 'Prodesign',
            'phone' => '0957470371',
            'odoo_partner_id' => '58',
            'odoo_lead_id' => null,
        ]);

        app(ImportOdooCrmClients::class)->handle(200);

        $this->getJson('/api/admin/clients')
            ->assertOk()
            ->assertJsonPath('data.0.odoo_lead_id', '801')
            ->assertJsonPath('data.0.odoo_stage_name', 'تلغرام');

        Http::assertSent(function (Request $request): bool {
            $args = $request->data()['params']['args'] ?? [];

            return ($args[3] ?? null) === 'crm.lead' && ($args[4] ?? null) === 'create';
        });
    }

    public function test_quotation_keeps_existing_odoo_partner(): void
    {
        Http::preventStrayRequests();
        $this->fakeOdooDocuments();
        Http::fake($this->odooDocumentsHttpFake());

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $client = Client::factory()->create([
            'name' => 'Ahmad',
            'email' => null,
            'phone' => '0957470371',
            'company_name' => 'Prodesign',
            'odoo_partner_id' => '58',
        ]);
        $serviceRequest = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::Submitted,
        ]);

        $this->postJson("/api/admin/requests/{$serviceRequest->id}/quotation", [
            'amount' => 100,
            'notes' => 'عرض',
        ])->assertOk();

        $this->assertSame('58', $client->fresh()?->odoo_partner_id);
        Http::assertNotSent(function (Request $request): bool {
            $args = $request->data()['params']['args'] ?? [];

            return ($args[3] ?? null) === 'res.partner' && ($args[4] ?? null) === 'create';
        });
    }

    public function test_clients_index_does_not_replace_person_name_from_partner(): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        $this->fakeOdooDocuments();
        Http::fake([
            'https://odoo.test/jsonrpc' => function (Request $request) {
                $params = $request->data()['params'] ?? [];
                if (($params['service'] ?? '') === 'common') {
                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2], 200);
                }

                $args = $params['args'] ?? [];
                $model = (string) ($args[3] ?? '');
                $action = (string) ($args[4] ?? '');

                if ($model === 'res.partner' && $action === 'search_read') {
                    return Http::response(['jsonrpc' => '2.0', 'result' => [[
                        'id' => 58,
                        'name' => '0957470371',
                        'email' => false,
                        'phone' => '🆕 طلب جديد',
                    ]]], 200);
                }

                if ($model === 'crm.lead' && $action === 'search_read') {
                    $domain = $args[5][0] ?? [];
                    foreach ($domain as $clause) {
                        if (($clause[0] ?? '') === 'id') {
                            return Http::response(['jsonrpc' => '2.0', 'result' => [[
                                'id' => 801,
                                'stage_id' => [11, 'تلغرام'],
                                'contact_name' => 'Ahmad Ali',
                                'partner_name' => 'Prodesign',
                                'email_from' => false,
                                'phone' => '0957470371',
                                'partner_id' => [58, '0957470371'],
                            ]]], 200);
                        }
                    }

                    return Http::response(['jsonrpc' => '2.0', 'result' => []], 200);
                }

                if ($model === 'crm.lead' && $action === 'create') {
                    return Http::response(['jsonrpc' => '2.0', 'result' => 801], 200);
                }

                if ($model === 'crm.stage' && $action === 'search_read') {
                    return Http::response(['jsonrpc' => '2.0', 'result' => [
                        ['id' => 11, 'name' => 'تلغرام'],
                    ]], 200);
                }

                return Http::response(['jsonrpc' => '2.0', 'result' => true], 200);
            },
        ]);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        Client::factory()->create([
            'name' => 'Ahmad Ali',
            'phone' => '0957470371',
            'company_name' => 'Prodesign',
            'odoo_partner_id' => '58',
            'odoo_lead_id' => null,
        ]);

        $this->getJson('/api/admin/clients')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Ahmad Ali')
            ->assertJsonPath('data.0.phone', '0957470371');
    }

    public function test_clients_index_imports_odoo_leads_from_any_pipeline_stage(): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        $this->fakeOdooDocuments();
        Http::fake([
            'https://odoo.test/jsonrpc' => function (Request $request) {
                $params = $request->data()['params'] ?? [];
                if (($params['service'] ?? '') === 'common') {
                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2], 200);
                }

                $args = $params['args'] ?? [];
                $model = (string) ($args[3] ?? '');
                $action = (string) ($args[4] ?? '');

                if ($model === 'crm.lead' && $action === 'search_read') {
                    return Http::response(['jsonrpc' => '2.0', 'result' => [[
                        'id' => 902,
                        'name' => 'Qualified deal',
                        'contact_name' => 'Pipeline Client',
                        'partner_name' => 'Pipeline Co',
                        'partner_id' => [90, 'Pipeline Co'],
                        'email_from' => 'pipeline@hoc.test',
                        'phone' => '+963700000000',
                        'stage_id' => [12, 'مؤهل'],
                    ]]], 200);
                }

                if ($model === 'res.partner' && $action === 'search_read') {
                    return Http::response(['jsonrpc' => '2.0', 'result' => []], 200);
                }

                return Http::response(['jsonrpc' => '2.0', 'result' => true], 200);
            },
        ]);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        app(ImportOdooCrmClients::class)->handle(200);

        $this->getJson('/api/admin/clients')
            ->assertOk()
            ->assertJsonFragment([
                'email' => 'pipeline@hoc.test',
                'odoo_lead_id' => '902',
                'odoo_stage_name' => 'مؤهل',
            ]);
    }

    public function test_admin_lists_confirmed_odoo_quotations_from_live_odoo(): void
    {
        Http::preventStrayRequests();
        config([
            'services.odoo.enabled' => true,
            'services.odoo.url' => 'https://odoo.test',
            'services.odoo.db' => 'hoc',
            'services.odoo.username' => 'admin',
            'services.odoo.api_key' => 'secret-key',
            'services.odoo.use_json2' => false,
        ]);
        Http::fake([
            'https://odoo.test/jsonrpc' => function (Request $request) {
                $params = $request->data()['params'] ?? [];
                if (($params['service'] ?? '') === 'common') {
                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2], 200);
                }

                $args = $params['args'] ?? [];
                if (($args[3] ?? null) === 'sale.order' && ($args[4] ?? null) === 'search_read') {
                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => [[
                        'id' => 301,
                        'name' => 'S00031',
                        'partner_id' => [44, 'Damastech'],
                        'amount_total' => 250,
                        'state' => 'sale',
                        'client_order_ref' => 'REQ-2026-000001',
                        'origin' => false,
                        'date_order' => '2026-09-17 10:00:00',
                    ]]], 200);
                }

                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => []], 200);
            },
        ]);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/odoo/quotations')
            ->assertOk()
            ->assertJsonPath('data.0.state', 'sale')
            ->assertJsonPath('data.0.amount_total', 250);
    }

    public function test_admin_lists_odoo_invoices_from_live_odoo(): void
    {
        Http::preventStrayRequests();
        config([
            'services.odoo.enabled' => true,
            'services.odoo.url' => 'https://odoo.test',
            'services.odoo.db' => 'hoc',
            'services.odoo.username' => 'admin',
            'services.odoo.api_key' => 'secret-key',
            'services.odoo.use_json2' => false,
        ]);
        Http::fake([
            'https://odoo.test/jsonrpc' => function (Request $request) {
                $params = $request->data()['params'] ?? [];
                if (($params['service'] ?? '') === 'common') {
                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2], 200);
                }

                $args = $params['args'] ?? [];
                if (($args[3] ?? null) === 'account.move' && ($args[4] ?? null) === 'search_read') {
                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => [[
                        'id' => 501,
                        'name' => 'INV/2026/0001',
                        'partner_id' => [44, 'Damastech'],
                        'amount_total' => 250,
                        'state' => 'posted',
                        'payment_state' => 'not_paid',
                        'invoice_origin' => 'S00031',
                        'ref' => 'REQ-2026-000001',
                        'invoice_date' => '2026-09-17',
                    ]]], 200);
                }

                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => []], 200);
            },
        ]);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/odoo/invoices')
            ->assertOk()
            ->assertJsonPath('data.0.state', 'posted')
            ->assertJsonPath('data.0.payment_state', 'not_paid')
            ->assertJsonPath('data.0.amount_total', 250);
    }

    public function test_admin_request_show_hydrates_live_odoo_quotation_and_invoice(): void
    {
        Http::preventStrayRequests();
        config([
            'services.odoo.enabled' => true,
            'services.odoo.url' => 'https://odoo.test',
            'services.odoo.db' => 'hoc',
            'services.odoo.username' => 'admin',
            'services.odoo.api_key' => 'secret-key',
            'services.odoo.use_json2' => false,
        ]);
        Http::fake([
            'https://odoo.test/jsonrpc' => function (Request $request) {
                $params = $request->data()['params'] ?? [];
                if (($params['service'] ?? '') === 'common') {
                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2], 200);
                }

                $args = $params['args'] ?? [];
                $model = $args[3] ?? null;
                $action = $args[4] ?? null;

                if ($model === 'sale.order' && $action === 'search_read') {
                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => [[
                        'id' => 301,
                        'name' => 'S00031',
                        'partner_id' => [44, 'Damastech'],
                        'amount_total' => 250,
                        'state' => 'sent',
                        'client_order_ref' => 'REQ-TEST',
                        'origin' => false,
                        'date_order' => '2026-09-17 10:00:00',
                    ]]], 200);
                }

                if ($model === 'account.move' && $action === 'search_read') {
                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => [[
                        'id' => 501,
                        'name' => 'INV/2026/0001',
                        'partner_id' => [44, 'Damastech'],
                        'amount_total' => 250,
                        'state' => 'posted',
                        'payment_state' => 'not_paid',
                        'invoice_origin' => 'REQ-TEST',
                        'ref' => 'REQ-TEST',
                        'invoice_date' => '2026-09-17',
                    ]]], 200);
                }

                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => []], 200);
            },
        ]);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $client = Client::factory()->create(['telegram_user_id' => '213309826']);
        $serviceRequest = ServiceRequest::factory()->create([
            'client_id' => $client->id,
            'number' => 'REQ-TEST',
            'odoo_quotation_id' => '301',
            'quotation_amount' => 10,
            'google_drive_folder_id' => 'folder-abc',
        ]);

        $this->getJson("/api/admin/requests/{$serviceRequest->id}")
            ->assertOk()
            ->assertJsonPath('data.odoo_quotation_id', '301')
            ->assertJsonPath('data.odoo_invoice_id', '501')
            ->assertJsonPath('data.odoo_quotation_live.state', 'sent')
            ->assertJsonPath('data.odoo_invoice_live.state', 'posted')
            ->assertJsonPath('data.google_drive_folder_url', 'https://drive.google.com/drive/folders/folder-abc')
            ->assertJsonPath('data.google_drive_folder_ready', true)
            ->assertJsonPath('data.client.telegram_url', 'tg://user?id=213309826');

        $this->assertEquals(250.0, (float) $serviceRequest->fresh()->quotation_amount);
    }

    public function test_admin_request_show_creates_missing_drive_folder_when_paid(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $serviceRequest = ServiceRequest::factory()->create([
            'status' => RequestStatus::PaymentConfirmed,
            'paid_at' => now(),
            'google_drive_folder_id' => null,
            'title' => 'هوية',
        ]);

        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('ensureFolderPath')->once()->andReturn('folder-show');
        });

        $this->getJson("/api/admin/requests/{$serviceRequest->id}")
            ->assertOk()
            ->assertJsonPath('data.google_drive_folder_url', 'https://drive.google.com/drive/folders/folder-show');

        $this->assertSame('folder-show', $serviceRequest->fresh()?->google_drive_folder_id);
    }

    public function test_admin_request_show_links_parent_drive_when_request_folder_missing(): void
    {
        config(['services.google.drive_parent_folder_id' => 'parent-hoc']);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $serviceRequest = ServiceRequest::factory()->create([
            'status' => RequestStatus::PaymentConfirmed,
            'paid_at' => now(),
            'google_drive_folder_id' => null,
        ]);

        $this->getJson("/api/admin/requests/{$serviceRequest->id}")
            ->assertOk()
            ->assertJsonPath('data.google_drive_folder_ready', false)
            ->assertJsonPath('data.google_drive_folder_url', 'https://drive.google.com/drive/folders/parent-hoc');
    }

    public function test_admin_can_ensure_drive_folder(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $serviceRequest = ServiceRequest::factory()->create([
            'status' => RequestStatus::Submitted,
            'google_drive_folder_id' => null,
            'title' => 'هوية',
        ]);

        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('ensureFolderPath')->once()->andReturn('folder-ensured');
        });

        $this->postJson("/api/admin/requests/{$serviceRequest->id}/ensure-drive-folder")
            ->assertOk()
            ->assertJsonPath('data.google_drive_folder_ready', true)
            ->assertJsonPath('data.google_drive_folder_id', 'folder-ensured')
            ->assertJsonPath('data.google_drive_folder_url', 'https://drive.google.com/drive/folders/folder-ensured');
    }

    public function test_ensure_drive_folder_explains_when_not_configured(): void
    {
        config([
            'services.google.credentials_json' => null,
            'services.google.drive_parent_folder_id' => null,
        ]);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $serviceRequest = ServiceRequest::factory()->create([
            'status' => RequestStatus::PaymentConfirmed,
            'paid_at' => now(),
            'google_drive_folder_id' => null,
        ]);

        $this->postJson("/api/admin/requests/{$serviceRequest->id}/ensure-drive-folder")
            ->assertStatus(422)
            ->assertJsonValidationErrors('drive');
    }

    public function test_admin_request_show_posts_and_pays_draft_odoo_invoice_when_locally_paid(): void
    {
        Http::preventStrayRequests();
        config([
            'services.odoo.enabled' => true,
            'services.odoo.url' => 'https://odoo.test',
            'services.odoo.db' => 'hoc',
            'services.odoo.username' => 'admin',
            'services.odoo.api_key' => 'secret-key',
            'services.odoo.use_json2' => false,
        ]);

        $invoice = [
            'id' => 22,
            'name' => 'INV/2026/0022',
            'partner_id' => [44, 'Startup Build'],
            'amount_total' => 3830.0,
            'amount_residual' => 3830.0,
            'state' => 'draft',
            'payment_state' => 'not_paid',
            'invoice_origin' => 'REQ-2026-000012',
            'ref' => 'REQ-2026-000012',
            'invoice_date' => '2026-09-19',
        ];

        Http::fake([
            'https://odoo.test/jsonrpc' => function (Request $request) use (&$invoice) {
                $params = $request->data()['params'] ?? [];
                if (($params['service'] ?? '') === 'common') {
                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2], 200);
                }

                $args = $params['args'] ?? [];
                $model = $args[3] ?? null;
                $action = $args[4] ?? null;

                if ($model === 'sale.order' && $action === 'search_read') {
                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => []], 200);
                }

                if ($model === 'account.move' && $action === 'search_read') {
                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => [$invoice]], 200);
                }

                if ($model === 'account.move' && $action === 'action_post') {
                    $invoice['state'] = 'posted';

                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => true], 200);
                }

                if ($model === 'account.journal' && $action === 'search_read') {
                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => [[
                        'id' => 8,
                        'name' => 'Cash',
                        'type' => 'cash',
                    ]]], 200);
                }

                if ($model === 'account.payment.register' && $action === 'create') {
                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => 701], 200);
                }

                if ($model === 'account.payment.register' && $action === 'action_create_payments') {
                    $invoice['payment_state'] = 'paid';
                    $invoice['amount_residual'] = 0.0;

                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => true], 200);
                }

                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => []], 200);
            },
        ]);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $serviceRequest = ServiceRequest::factory()->create([
            'number' => 'REQ-2026-000012',
            'status' => RequestStatus::PaymentConfirmed,
            'odoo_invoice_id' => '22',
            'quotation_amount' => 3830,
            'amount_total' => 3830,
            'amount_paid' => 3830,
            'amount_remaining' => 0,
        ]);

        $this->getJson("/api/admin/requests/{$serviceRequest->id}")
            ->assertOk()
            ->assertJsonPath('data.odoo_invoice_id', '22')
            ->assertJsonPath('data.odoo_invoice_live.state', 'posted')
            ->assertJsonPath('data.odoo_invoice_live.payment_state', 'paid');

        Http::assertSent(function (Request $request): bool {
            $args = $request->data()['params']['args'] ?? [];

            return ($args[3] ?? null) === 'account.move'
                && ($args[4] ?? null) === 'action_post';
        });

        Http::assertSent(function (Request $request): bool {
            $args = $request->data()['params']['args'] ?? [];

            return ($args[3] ?? null) === 'account.payment.register'
                && ($args[4] ?? null) === 'action_create_payments';
        });
    }

    public function test_admin_can_sync_odoo_partners_into_clients(): void
    {
        Http::preventStrayRequests();
        config([
            'services.odoo.enabled' => true,
            'services.odoo.url' => 'https://odoo.test',
            'services.odoo.db' => 'hoc',
            'services.odoo.username' => 'admin',
            'services.odoo.api_key' => 'secret-key',
        ]);
        Http::fake([
            'https://odoo.test/jsonrpc' => Http::sequence()
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => [[
                    'id' => 55,
                    'name' => 'Odoo Client',
                    'email' => 'odoo@hoc.test',
                    'phone' => '+963111',
                    'mobile' => false,
                ]]], 200),
        ]);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/odoo/sync-partners')
            ->assertOk()
            ->assertJsonPath('data.created', 1);

        $this->assertDatabaseHas('clients', [
            'name' => 'Odoo Client',
            'email' => 'odoo@hoc.test',
            'odoo_partner_id' => '55',
        ]);
    }

    public function test_admin_can_create_employee_and_push_to_odoo(): void
    {
        Http::preventStrayRequests();
        config([
            'services.odoo.enabled' => true,
            'services.odoo.url' => 'https://odoo.test',
            'services.odoo.db' => 'hoc',
            'services.odoo.username' => 'admin',
            'services.odoo.api_key' => 'secret-key',
        ]);
        Http::fake([
            'https://odoo.test/jsonrpc' => Http::sequence()
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => []], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 3, 'result' => []], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 4, 'result' => 91], 200),
        ]);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/employees', [
            'name' => 'Sara Saleh',
            'email' => 'sara@hoc.test',
            'phone' => '+963999000222',
            'profession' => EmployeeProfession::Sales->value,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Sara Saleh')
            ->assertJsonPath('data.odoo_employee_id', '91');

        $this->assertDatabaseHas('employees', [
            'email' => 'sara@hoc.test',
            'odoo_employee_id' => '91',
        ]);
    }

    public function test_updating_employee_writes_odoo_ids_and_vals_positionally(): void
    {
        Http::preventStrayRequests();
        config([
            'services.odoo.enabled' => true,
            'services.odoo.url' => 'https://odoo.test',
            'services.odoo.db' => 'hoc',
            'services.odoo.username' => 'admin',
            'services.odoo.api_key' => 'secret-key',
            'services.odoo.use_json2' => false,
        ]);

        $writeArgs = null;
        Http::fake([
            'https://odoo.test/jsonrpc' => function (Request $request) use (&$writeArgs) {
                $params = $request->data()['params'] ?? [];
                $service = $params['service'] ?? '';
                $method = $params['method'] ?? '';

                if ($service === 'common' && $method === 'authenticate') {
                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2], 200);
                }

                $args = $params['args'] ?? [];
                $model = (string) ($args[3] ?? '');
                $action = (string) ($args[4] ?? '');
                if ($model === 'hr.employee' && $action === 'write') {
                    $writeArgs = $args[5] ?? null;

                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => true], 200);
                }

                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => null], 200);
            },
        ]);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $employee = Employee::factory()->create([
            'name' => 'Sara Saleh',
            'email' => 'sara@hoc.test',
            'phone' => '+963999000222',
            'profession' => EmployeeProfession::Sales,
            'odoo_employee_id' => '66',
        ]);

        $this->putJson("/api/admin/employees/{$employee->id}", [
            'name' => 'Sara Updated',
            'profession' => EmployeeProfession::Sales->value,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Sara Updated')
            ->assertJsonPath('data.odoo_employee_id', '66');

        $this->assertIsArray($writeArgs);
        $this->assertSame([66], $writeArgs[0]);
        $this->assertSame('Sara Updated', $writeArgs[1]['name']);
        $this->assertArrayNotHasKey('ids', $writeArgs);
        $this->assertArrayNotHasKey('vals', $writeArgs);
    }

    public function test_admin_can_sync_odoo_employees_and_push_local_staff(): void
    {
        Http::preventStrayRequests();
        config([
            'services.odoo.enabled' => true,
            'services.odoo.url' => 'https://odoo.test',
            'services.odoo.db' => 'hoc',
            'services.odoo.username' => 'admin',
            'services.odoo.api_key' => 'secret-key',
        ]);
        Http::fake([
            'https://odoo.test/jsonrpc' => Http::sequence()
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => [[
                    'id' => 66,
                    'name' => 'Odoo Staff',
                    'work_email' => 'staff@hoc.test',
                    'work_phone' => '+963222',
                    'mobile_phone' => false,
                    'barcode' => 'EMP-0099',
                    'active' => true,
                ]]], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 3, 'result' => []], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 4, 'result' => []], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 5, 'result' => 92], 200),
        ]);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        Employee::factory()->create([
            'name' => 'Local Designer',
            'email' => 'designer@hoc.test',
            'profession' => EmployeeProfession::Design,
            'odoo_employee_id' => null,
        ]);

        $this->postJson('/api/admin/odoo/sync-employees')
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.pushed', 1);

        $this->assertDatabaseHas('employees', [
            'name' => 'Odoo Staff',
            'email' => 'staff@hoc.test',
            'odoo_employee_id' => '66',
        ]);
        $this->assertDatabaseHas('employees', [
            'email' => 'designer@hoc.test',
            'odoo_employee_id' => '92',
        ]);
    }

    public function test_admin_employees_index_imports_odoo_staff_without_a_sync_button(): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        $this->fakeOdooDocuments();
        Http::fake([
            'https://odoo.test/jsonrpc' => function (Request $request) {
                $params = $request->data()['params'] ?? [];
                $service = $params['service'] ?? '';
                $method = $params['method'] ?? '';

                if ($service === 'common' && $method === 'authenticate') {
                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2], 200);
                }

                $args = $params['args'] ?? [];
                $model = (string) ($args[3] ?? '');
                $action = (string) ($args[4] ?? '');

                if ($model === 'hr.employee' && $action === 'search_read') {
                    return Http::response(['jsonrpc' => '2.0', 'result' => [[
                        'id' => 66,
                        'name' => 'Odoo Staff',
                        'work_email' => 'staff@hoc.test',
                        'work_phone' => '+963222',
                        'mobile_phone' => false,
                        'barcode' => 'EMP-0099',
                        'active' => true,
                    ]]], 200);
                }

                if ($model === 'hr.employee' && $action === 'search') {
                    return Http::response(['jsonrpc' => '2.0', 'result' => []], 200);
                }

                return Http::response(['jsonrpc' => '2.0', 'result' => null], 200);
            },
        ]);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/employees')
            ->assertOk()
            ->assertJsonFragment(['email' => 'staff@hoc.test']);

        $this->assertDatabaseHas('employees', [
            'name' => 'Odoo Staff',
            'email' => 'staff@hoc.test',
            'odoo_employee_id' => '66',
        ]);
    }

    public function test_admin_employees_index_hydrates_live_odoo_fields(): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        $this->fakeOdooDocuments();
        Http::fake([
            'https://odoo.test/jsonrpc' => function (Request $request) {
                $params = $request->data()['params'] ?? [];
                if (($params['service'] ?? '') === 'common') {
                    return Http::response(['jsonrpc' => '2.0', 'result' => 2], 200);
                }

                $args = $params['args'] ?? [];
                if (($args[3] ?? null) === 'hr.employee' && ($args[4] ?? null) === 'search_read') {
                    return Http::response(['jsonrpc' => '2.0', 'result' => [[
                        'id' => 66,
                        'name' => 'Live Name',
                        'work_email' => 'live@hoc.test',
                        'work_phone' => '+963333',
                        'mobile_phone' => false,
                        'barcode' => 'EMP-0066',
                        'active' => true,
                    ]]], 200);
                }

                return Http::response(['jsonrpc' => '2.0', 'result' => []], 200);
            },
        ]);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        Employee::factory()->create([
            'name' => 'Stale',
            'email' => 'stale@hoc.test',
            'odoo_employee_id' => '66',
        ]);

        $this->getJson('/api/admin/employees')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Live Name')
            ->assertJsonPath('data.0.email', 'live@hoc.test');
    }

    public function test_admin_delete_employee_unlinks_odoo_record(): void
    {
        Http::preventStrayRequests();
        $this->fakeOdooDocuments();
        Http::fake($this->odooDocumentsHttpFake());

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $employee = Employee::factory()->create([
            'odoo_employee_id' => '66',
            'telegram_user_id' => null,
        ]);

        $this->deleteJson("/api/admin/employees/{$employee->id}")
            ->assertOk();

        $this->assertDatabaseMissing('employees', ['id' => $employee->id]);
        Http::assertSent(function (Request $request): bool {
            $args = $request->data()['params']['args'] ?? [];

            return ($args[3] ?? null) === 'hr.employee' && ($args[4] ?? null) === 'unlink';
        });
    }

    public function test_admin_employees_index_removes_local_row_deleted_in_odoo(): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        $this->fakeOdooDocuments();
        Http::fake($this->odooDocumentsHttpFake());

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $employee = Employee::factory()->create([
            'odoo_employee_id' => '66',
            'telegram_user_id' => null,
        ]);

        $this->getJson('/api/admin/employees')->assertOk();

        $this->assertDatabaseMissing('employees', ['id' => $employee->id]);
    }

    public function test_sync_partners_pushes_local_clients_without_odoo_id(): void
    {
        Http::preventStrayRequests();
        config([
            'services.odoo.enabled' => true,
            'services.odoo.url' => 'https://odoo.test',
            'services.odoo.db' => 'hoc',
            'services.odoo.username' => 'admin',
            'services.odoo.api_key' => 'secret-key',
        ]);
        Http::fake([
            'https://odoo.test/jsonrpc' => Http::sequence()
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => []], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 3, 'result' => []], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 4, 'result' => []], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 5, 'result' => 80], 200),
        ]);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        Client::factory()->create([
            'name' => 'Local Studio',
            'email' => 'local-studio@hoc.test',
            'odoo_partner_id' => null,
        ]);

        $this->postJson('/api/admin/odoo/sync-partners')
            ->assertOk()
            ->assertJsonPath('data.pushed', 1);

        $this->assertDatabaseHas('clients', [
            'email' => 'local-studio@hoc.test',
            'odoo_partner_id' => '80',
        ]);
    }

    public function test_admin_can_import_crm_clients_from_odoo(): void
    {
        Http::preventStrayRequests();
        config([
            'services.odoo.enabled' => true,
            'services.odoo.url' => 'https://odoo.test',
            'services.odoo.db' => 'hoc',
            'services.odoo.username' => 'admin',
            'services.odoo.api_key' => 'secret-key',
            'services.odoo.use_json2' => false,
        ]);

        Http::fake([
            'https://odoo.test/jsonrpc' => Http::sequence()
                ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => [[
                    'id' => 701,
                    'name' => 'Booth project',
                    'contact_name' => 'CRM Lead Client',
                    'partner_id' => [88, 'CRM Lead Client'],
                    'email_from' => 'crm-lead@hoc.test',
                    'phone' => '+963700111222',
                ]]], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 3, 'result' => 2], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 4, 'result' => []], 200),
        ]);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/odoo/import-crm-clients')
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.crm_leads', 1);

        $this->assertDatabaseHas('clients', [
            'name' => 'CRM Lead Client',
            'email' => 'crm-lead@hoc.test',
            'odoo_partner_id' => '88',
            'odoo_lead_id' => '701',
        ]);
    }

    public function test_crm_import_does_not_request_removed_mobile_field(): void
    {
        Http::preventStrayRequests();
        config([
            'services.odoo.enabled' => true,
            'services.odoo.url' => 'https://odoo.test',
            'services.odoo.db' => 'hoc',
            'services.odoo.username' => 'admin',
            'services.odoo.api_key' => 'secret-key',
            'services.odoo.use_json2' => true,
        ]);

        Http::fake(function (Request $request) {
            if ($request->url() === 'https://odoo.test/json/2/crm.lead/search_read') {
                $fields = $request['fields'] ?? [];
                if (in_array('mobile', $fields, true)) {
                    return Http::response([
                        'name' => 'builtins.ValueError',
                        'message' => "Invalid field 'mobile' on 'crm.lead'",
                    ], 500);
                }

                return Http::response([[
                    'id' => 701,
                    'name' => 'Booth project',
                    'contact_name' => 'CRM Lead Client',
                    'partner_id' => [88, 'CRM Lead Client'],
                    'email_from' => 'crm-lead@hoc.test',
                    'phone' => '+963700111222',
                ]], 200);
            }

            if ($request->url() === 'https://odoo.test/json/2/res.partner/search_read') {
                return Http::response([], 200);
            }

            return Http::response(['message' => 'Unexpected Odoo call: '.$request->url()], 500);
        });

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/odoo/import-crm-clients')
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.crm_leads', 1);

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://odoo.test/json/2/crm.lead/search_read') {
                return false;
            }

            $fields = $request['fields'] ?? [];

            return is_array($fields) && ! in_array('mobile', $fields, true);
        });
    }
}
