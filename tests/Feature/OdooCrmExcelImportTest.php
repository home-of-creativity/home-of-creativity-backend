<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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

        $leadId = 900;
        $leads = [];

        Http::fake([
            'https://odoo.test/jsonrpc' => function (Request $request) use (&$leadId, &$leads) {
                $params = $request->data()['params'] ?? [];
                $service = $params['service'] ?? '';
                $method = $params['method'] ?? '';

                if ($service === 'common' && $method === 'authenticate') {
                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2], 200);
                }

                $args = $params['args'] ?? [];
                $model = (string) ($args[3] ?? '');
                $action = (string) ($args[4] ?? '');

                if ($model === 'crm.stage' && $action === 'search_read') {
                    return Http::response(['jsonrpc' => '2.0', 'result' => [
                        ['id' => 11, 'name' => 'العملاء المحتملون'],
                    ]], 200);
                }

                if ($model === 'crm.team' && $action === 'search_read') {
                    return Http::response(['jsonrpc' => '2.0', 'result' => [
                        ['id' => 21, 'name' => 'تلغرام'],
                    ]], 200);
                }

                if ($model === 'crm.lead' && $action === 'search') {
                    return Http::response(['jsonrpc' => '2.0', 'result' => []], 200);
                }

                if ($model === 'crm.lead' && $action === 'create') {
                    $leadId++;
                    $values = $args[5][0][0] ?? [];
                    $leads[] = [
                        'id' => $leadId,
                        'name' => $values['name'] ?? 'Lead',
                        'contact_name' => $values['contact_name'] ?? null,
                        'partner_name' => $values['partner_name'] ?? null,
                        'partner_id' => false,
                        'email_from' => false,
                        'phone' => false,
                    ];

                    return Http::response(['jsonrpc' => '2.0', 'result' => $leadId], 200);
                }

                if ($model === 'crm.lead' && $action === 'search_read') {
                    return Http::response(['jsonrpc' => '2.0', 'result' => $leads], 200);
                }

                if ($model === 'res.partner' && $action === 'search_read') {
                    return Http::response(['jsonrpc' => '2.0', 'result' => []], 200);
                }

                if ($model === 'res.partner' && $action === 'search') {
                    return Http::response(['jsonrpc' => '2.0', 'result' => []], 200);
                }

                if ($model === 'res.partner' && $action === 'create') {
                    return Http::response(['jsonrpc' => '2.0', 'result' => 44], 200);
                }

                if ($model === 'res.partner' && $action === 'write') {
                    return Http::response(['jsonrpc' => '2.0', 'result' => true], 200);
                }

                return Http::response(['jsonrpc' => '2.0', 'result' => null], 200);
            },
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
