<?php

namespace Tests\Feature;

use App\Enums\EmployeeProfession;
use App\Enums\RequestStatus;
use App\Jobs\ClassifyWithGeminiJob;
use App\Models\Client;
use App\Models\Employee;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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
                ->push(['jsonrpc' => '2.0', 'id' => 4, 'result' => 80], 200),
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
