<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Jobs\ClassifyWithGeminiJob;
use App\Models\Client;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
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
            ->assertJsonPath('data.requests', 0);
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
        ])->assertOk()
            ->assertJsonPath('data.gemini_status', 'pending');

        $this->assertSame(RequestStatus::AwaitingPayment, $serviceRequest->fresh()->status);
        Bus::assertDispatched(ClassifyWithGeminiJob::class);
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
        ])->assertUnprocessable();

        $this->patchJson("/api/admin/requests/{$serviceRequest->id}", [
            'status' => 'payment_confirmed',
        ])->assertUnprocessable();

        $this->assertSame('submitted', $serviceRequest->fresh()?->status->value);
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

    public function test_admin_can_create_client_and_push_to_odoo(): void
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
                ->push(['jsonrpc' => '2.0', 'id' => 3, 'result' => 44], 200),
        ]);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/clients', [
            'name' => 'Studio Client',
            'email' => 'studio@hoc.test',
            'phone' => '+963999000111',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Studio Client')
            ->assertJsonPath('data.odoo_partner_id', '44');

        $this->assertDatabaseHas('clients', [
            'email' => 'studio@hoc.test',
            'odoo_partner_id' => '44',
        ]);
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
}
