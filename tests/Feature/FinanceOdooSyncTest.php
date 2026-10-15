<?php

namespace Tests\Feature;

use App\Actions\SyncOdooInvoices;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\OdooInvoice;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
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

    /** @var list<int> invoices deleted in Odoo; every other id asked for still exists */
    private array $deletedIds = [];

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
                    $domain = $args[5][0] ?? [];
                    $asked = ($domain[0][0] ?? null) === 'id' ? array_map('intval', (array) $domain[0][2]) : [1];
                    $rows = array_map(fn (int $id): array => ['id' => $id], array_values(array_diff($asked, $this->deletedIds)));
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
        $this->assertSame('INV/2026/0501', $local->fresh()->invoice_number, 'the payment record shows the number Odoo prints');

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
        app(SyncOdooInvoices::class)->handle();
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
        $this->deletedIds = [501];
        $this->artisan('odoo:sync-invoices', ['--full' => true])->assertSuccessful();
        $this->assertFalse(OdooInvoice::query()->where('odoo_id', 501)->exists());
        $this->assertSame(2, OdooInvoice::query()->count());
        $this->assertNull($local->fresh(), 'the payment record of a deleted invoice goes too');
        $this->assertNull($request->fresh()->odoo_invoice_id);
    }

    public function test_an_overdue_invoice_paid_in_odoo_moves_to_received(): void
    {
        $this->odooRows = [
            $this->invoice(502, 'Nour Co', 399, 399, 'posted', 'not_paid', '2026-10-01', '2026-10-08'),
            $this->invoice(504, 'Nour Co', 50, 50, 'posted', 'not_paid', '2026-10-01', '2026-10-09'),
        ];
        $this->getJson('/api/admin/finance')
            ->assertOk()
            ->assertJsonPath('data.revenue_paid', 0)
            ->assertJsonPath('data.revenue_open', 449)
            ->assertJsonPath('data.revenue_overdue', 449);

        // Paid after the due date: Odoo reports "in payment" until the bank line is matched, then "paid".
        $this->odooRows = [
            $this->invoice(502, 'Nour Co', 399, 0, 'posted', 'in_payment', '2026-10-01', '2026-10-08', '2026-10-10 09:01:00'),
            $this->invoice(504, 'Nour Co', 50, 0, 'posted', 'paid', '2026-10-01', '2026-10-09', '2026-10-10 09:01:00'),
        ];
        app(SyncOdooInvoices::class)->handle();
        $this->getJson('/api/admin/finance')
            ->assertOk()
            ->assertJsonPath('data.revenue_paid', 449)
            ->assertJsonPath('data.revenue_open', 0)
            ->assertJsonPath('data.revenue_overdue', 0)
            ->assertJsonPath('data.state_counts.paid', 2)
            ->assertJsonPath('data.state_counts.overdue', 0);
    }

    public function test_an_invoice_deleted_in_odoo_leaves_the_finance_page_within_a_minute(): void
    {
        $client = Client::factory()->create(['company_name' => 'Damastech', 'odoo_partner_id' => '44']);
        $request = ServiceRequest::factory()->for($client)->create(['odoo_invoice_id' => '501']);
        $local = Invoice::query()->create([
            'request_id' => $request->id,
            'invoice_number' => 'INV-REQ-LOCAL-rcv1',
            'amount' => 1000,
            'kind' => 'received',
            'status' => 'issued',
            'odoo_invoice_id' => '501',
            'issued_at' => now(),
        ]);
        $kept = Invoice::query()->create([
            'request_id' => $request->id,
            'invoice_number' => 'INV-REQ-LOCAL-rcv2',
            'amount' => 200,
            'kind' => 'received',
            'status' => 'issued',
            'issued_at' => now(),
        ]);
        $this->odooRows = [
            $this->invoice(501, 'Damastech', 1000, 1000, 'posted', 'not_paid', '2026-10-01', '2026-10-20'),
            $this->invoice(502, 'Nour Co', 300, 300, 'posted', 'not_paid', '2026-09-01', '2026-09-15'),
        ];
        app(SyncOdooInvoices::class)->handle();
        $this->assertSame(2, OdooInvoice::query()->count());

        // Deleted in Odoo; nothing else changed there, so the incremental read returns no rows.
        $this->odooRows = [];
        $this->deletedIds = [501];
        $this->artisan('odoo:sync-invoices')->expectsOutputToContain('removed=1')->assertSuccessful();

        $this->assertFalse(OdooInvoice::query()->where('odoo_id', 501)->exists());
        $this->assertTrue(OdooInvoice::query()->where('odoo_id', 502)->exists());
        $this->assertNull($local->fresh());
        $this->assertNotNull($kept->fresh(), 'a payment that never reached Odoo stays');
        $this->assertNull($request->fresh()->odoo_invoice_id);
        $this->getJson('/api/admin/finance')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2)
            ->assertJsonMissing(['name' => 'INV/2026/0501'])
            ->assertJsonMissing(['name' => 'INV-REQ-LOCAL-rcv1']);
    }

    public function test_a_run_cut_off_by_a_deploy_blocks_the_odoo_jobs_for_minutes_not_a_day(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'odoo:'));

        $this->assertCount(4, $events);
        $events->each(function (Event $event): void {
            $this->assertTrue($event->withoutOverlapping);
            $this->assertLessThanOrEqual(30, $event->expiresAt, (string) $event->command);
        });
    }

    public function test_nothing_is_removed_when_odoo_answers_with_no_records_at_all(): void
    {
        $this->odooRows = [$this->invoice(502, 'Nour Co', 300, 300, 'posted', 'not_paid', '2026-09-01', '2026-09-15')];
        app(SyncOdooInvoices::class)->handle();

        $this->odooRows = [];
        $this->deletedIds = [1, 502];
        $this->artisan('odoo:sync-invoices')->expectsOutputToContain('removed=0')->assertSuccessful();
        $this->assertTrue(OdooInvoice::query()->where('odoo_id', 502)->exists());
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
