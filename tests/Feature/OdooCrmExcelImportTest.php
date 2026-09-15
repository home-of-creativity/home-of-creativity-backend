<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCrmImportSpreadsheet;
use Tests\TestCase;

class OdooCrmExcelImportTest extends TestCase
{
    use CreatesCrmImportSpreadsheet;
    use RefreshDatabase;

    public function test_admin_can_import_crm_clients_from_excel(): void
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
                    'id' => 11,
                    'name' => 'العملاء المحتملون',
                ]]], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 3, 'result' => []], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 4, 'result' => 901], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 5, 'result' => []], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 6, 'result' => 902], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 7, 'result' => 2], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 8, 'result' => [[
                    'id' => 901,
                    'name' => 'نيل أكرو',
                    'contact_name' => 'أحمد كمال',
                    'partner_id' => false,
                    'email_from' => false,
                    'phone' => false,
                ], [
                    'id' => 902,
                    'name' => 'شركة وايب',
                    'contact_name' => 'سيف',
                    'partner_id' => false,
                    'email_from' => false,
                    'phone' => false,
                ]]], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 9, 'result' => 2], 200)
                ->push(['jsonrpc' => '2.0', 'id' => 10, 'result' => []], 200),
        ]);

        $path = tempnam(sys_get_temp_dir(), 'crm-import-').'.xlsx';
        $this->createCrmImportSpreadsheet([
            ['المرحلة', 'الفرصة', 'اسم جهة الاتصال', 'اسم الشركة'],
            ['العملاء المحتلمون (39)', '', '', ''],
            ['العملاء المحتلمون', 'نيل أكرو', 'أحمد كمال', 'نيل أكرو'],
            ['صفقة رابحة', 'شركة وايب', 'سيف', 'شركة وايب للتجارة'],
        ], $path);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $this->post('/api/admin/odoo/import-crm-clients/excel', [
            'file' => new UploadedFile($path, 'crm.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ])
            ->assertOk()
            ->assertJsonPath('data.parsed', 2)
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonPath('data.created_in_odoo', 2)
            ->assertJsonPath('data.created', 2);

        $this->assertDatabaseHas('clients', [
            'name' => 'أحمد كمال',
        ]);

        @unlink($path);
    }
}
