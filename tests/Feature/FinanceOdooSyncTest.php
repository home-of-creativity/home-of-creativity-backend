<?php

namespace Tests\Feature;

use App\Actions\SyncOdooInvoices;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\OdooInvoice;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceOdooSyncTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $odooRows = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00', 'Asia/Damascus'));
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
            'https://odoo.test/jsonrpc' => function (Request $http) {
                $params = $http->data()['params'] ?? [];
                if (($params['service'] ?? '') === 'common') {
                    return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2], 200);
                }
                $args = $params['args'] ?? [];
                $fields = $args[5][1] ?? null;
                $rows = $this->odooRows;
                if (is_array($fields) && $fields === ['id']) {
                    $rows = array_map(fn (array $row): array => ['id' => $row['id']], $rows);
                }

                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $rows], 200);
            },
        ]);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_finance_follows_each_invoice_state_change_in_odoo(): void
    {
        $client = Client::factory()->create(['company_name' => 'Damastech', 'odoo_partner_id' => '44']);
        $request = ServiceRequest::factory()->for($client)->create(['odoo_invoice_id' => '501']);
        $local = Invoice::query()->create([
            'request_id' => $request->id,
            'invoice_number' => 'INV-LOCAL',
            'amount' => 1000,
            'kind' => 'received',
            'status' => 'issued',
            'odoo_invoice_id' => '501',
            'issued_at' => now(),
        ]);

        $this->odooRows = [
            $this->invoice(501, 'Damastech', 1000, 1000, 'posted', 'not_paid', '2026-10-01', '2026-10-20'),
            $this->invoice(502, 'Nour Co', 300, 300, 'posted', 'not_paid', '2026-09-01', '2026-09-15'),
            $this->invoice(503, 'Nour Co', 50, 50, 'draft', 'not_paid', '2026-10-05', null),
        ];

        $this->getJson('/api/admin/finance')
            ->assertOk()
            ->assertJsonPath('data.revenue_paid', 0)
            ->assertJsonPath('data.revenue_open', 1300)
            ->assertJsonPath('data.revenue_overdue', 300)
            ->assertJsonPath('data.state_counts.open', 1)
            ->assertJsonPath('data.state_counts.overdue', 1)
            ->assertJsonPath('data.state_counts.draft', 1)
            ->assertJsonPath('data.sync.error', null);
        $this->assertSame($request->id, OdooInvoice::query()->where('odoo_id', 501)->value('request_id'));
        $this->assertSame($client->id, OdooInvoice::query()->where('odoo_id', 501)->value('client_id'));

        // A partial payment registered in Odoo.
        $this->odooRows = [$this->invoice(501, 'Damastech', 1000, 400, 'posted', 'partial', '2026-10-01', '2026-10-20', '2026-10-10 09:01:00')];
        app(SyncOdooInvoices::class)->handle();
        $this->getJson('/api/admin/finance?invoice_state=partial')
            ->assertOk()
            ->assertJsonCount(1, 'data.invoices')
            ->assertJsonPath('data.invoices.0.collected', 600)
            ->assertJsonPath('data.invoices.0.residual', 400)
            ->assertJsonPath('data.revenue_paid', 600);
        $this->assertSame('partial', $local->fresh()->status);

        // Fully paid in Odoo.
        $this->odooRows = [$this->invoice(501, 'Damastech', 1000, 0, 'posted', 'paid', '2026-10-01', '2026-10-20', '2026-10-10 09:02:00')];
        app(SyncOdooInvoices::class)->handle();
        $this->getJson('/api/admin/finance?invoice_state=paid&client=Damastech')
            ->assertOk()
            ->assertJsonPath('data.invoices.0.state', 'paid')
            ->assertJsonPath('data.revenue_paid', 1000)
            ->assertJsonPath('data.revenue_open', 0);
        $this->assertSame('paid', $local->fresh()->status);

        // Cancelled in Odoo: the money leaves the received total right away.
        $this->odooRows = [$this->invoice(501, 'Damastech', 1000, 0, 'cancel', 'not_paid', '2026-10-01', '2026-10-20', '2026-10-10 09:03:00')];
        $this->postJson('/api/admin/finance/sync')->assertOk();
        $this->getJson('/api/admin/finance?client=Damastech')
            ->assertOk()
            ->assertJsonPath('data.invoices.0.state', 'cancelled')
            ->assertJsonPath('data.revenue_paid', 0);
        $this->assertSame('cancelled', $local->fresh()->status);

        // Deleted in Odoo: the hourly full walk removes it.
        $this->odooRows = [
            $this->invoice(502, 'Nour Co', 300, 300, 'posted', 'not_paid', '2026-09-01', '2026-09-15'),
            $this->invoice(503, 'Nour Co', 50, 50, 'draft', 'not_paid', '2026-10-05', null),
        ];
        $this->artisan('odoo:sync-invoices', ['--full' => true])->assertSuccessful();
        $this->assertFalse(OdooInvoice::query()->where('odoo_id', 501)->exists());
        $this->assertSame(2, OdooInvoice::query()->count());
    }

    public function test_finance_filters_search_amounts_dates_and_pages(): void
    {
        $this->odooRows = [];
        for ($i = 1; $i <= 30; $i++) {
            $this->odooRows[] = $this->invoice(600 + $i, $i % 2 === 0 ? 'Even Co' : 'Odd Co', $i * 10, 0, 'posted', 'paid', sprintf('2026-09-%02d', $i), null);
        }

        $page = $this->getJson('/api/admin/finance?per_page=10&page=2')->assertOk();
        $page->assertJsonPath('data.meta.total', 30)
            ->assertJsonPath('data.meta.last_page', 3)
            ->assertJsonCount(10, 'data.invoices')
            ->assertJsonPath('data.invoices.0.issued_at', '2026-09-20');

        $this->getJson('/api/admin/finance?client=Even&amount_min=100&amount_max=200')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 6)
            ->assertJsonPath('data.revenue_paid', 900);

        $this->getJson('/api/admin/finance?from=2026-09-05&to=2026-09-07&sort=amount&dir=asc')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 3)
            ->assertJsonPath('data.invoices.0.amount', 50);

        $this->getJson('/api/admin/finance?q=INV/2026/0607')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);

        $this->getJson('/api/admin/finance?invoice_state=bogus')->assertUnprocessable();
    }

    /**
     * @return array<string, mixed>
     */
    private function invoice(int $id, string $partner, float $total, float $residual, string $state, string $payment, string $date, ?string $due, string $written = '2026-10-10 09:00:00'): array
    {
        return [
            'id' => $id,
            'name' => sprintf('INV/2026/%04d', $id),
            'partner_id' => [$id === 501 ? 44 : 70, $partner],
            'amount_total' => $total,
            'amount_residual' => $residual,
            'currency_id' => [1, 'USD'],
            'state' => $state,
            'payment_state' => $payment,
            'invoice_origin' => false,
            'ref' => false,
            'invoice_date' => $date,
            'invoice_date_due' => $due ?? false,
            'write_date' => $written,
        ];
    }
}
