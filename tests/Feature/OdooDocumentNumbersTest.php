<?php

namespace Tests\Feature;

use App\Services\OdooClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Document numbers in Odoo: quotations stay QN, a confirmed order becomes S, the proforma
 * prints PF, and invoices move to INV continuing the year's count.
 */
class OdooDocumentNumbersTest extends TestCase
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $db = [];

    /** @var list<array{0: string, 1: string, 2: mixed}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.odoo.enabled' => true,
            'services.odoo.url' => 'https://odoo.test',
            'services.odoo.db' => 'hoc',
            'services.odoo.username' => 'admin',
            'services.odoo.api_key' => 'secret-key',
            'services.odoo.use_json2' => false,
        ]);
        $this->db = [
            'sale.order' => [
                41 => ['id' => 41, 'name' => 'QN00041', 'state' => 'sale'],
                42 => ['id' => 42, 'name' => 'QN00042', 'state' => 'draft'],
                43 => ['id' => 43, 'name' => 'QN00043', 'state' => 'sale'],
                44 => ['id' => 44, 'name' => 'S00044', 'state' => 'sale'],
                // Its order number is taken by order 44: it keeps QN.
                45 => ['id' => 45, 'name' => 'QN00044', 'state' => 'sale'],
            ],
            'account.journal' => [1 => ['id' => 1, 'code' => 'الفات', 'type' => 'sale']],
            'account.move' => [
                11 => ['id' => 11, 'name' => 'الفات/2026/00011', 'state' => 'posted', 'move_type' => 'out_invoice', 'journal_id' => [1, 'Sales'], 'invoice_date' => '2026-10-10', 'sequence_number' => 11],
                20 => ['id' => 20, 'name' => false, 'state' => 'draft', 'move_type' => 'out_invoice', 'journal_id' => [1, 'Sales'], 'invoice_date' => '2026-10-14', 'sequence_number' => 0],
                21 => ['id' => 21, 'name' => false, 'state' => 'draft', 'move_type' => 'out_invoice', 'journal_id' => [1, 'Sales'], 'invoice_date' => '2026-10-14', 'sequence_number' => 0],
            ],
            'ir.ui.view' => [500 => ['id' => 500, 'key' => 'sale.report_saleorder_document', 'inherit_id' => false, 'name' => 'report_saleorder_document']],
        ];
        Http::preventStrayRequests();
        Http::fake(['https://odoo.test/jsonrpc' => fn (Request $request) => $this->answer($request)]);
    }

    public function test_a_dry_run_lists_the_changes_and_writes_nothing(): void
    {
        $this->artisan('odoo:document-numbers', ['--dry-run' => true])
            ->expectsOutputToContain('order QN00041 → S00041 (dry run)')
            ->expectsOutputToContain('order QN00043 → S00043 (dry run)')
            ->expectsOutputToContain('invoices: journal code الفات → INV (dry run)')
            ->expectsOutputToContain('orders renamed: 2')
            ->assertSuccessful();

        $this->assertSame([], array_filter($this->calls, fn (array $call): bool => in_array($call[1], ['write', 'create'], true)));
        $this->assertSame('QN00041', $this->db['sale.order'][41]['name']);
    }

    public function test_the_command_renames_orders_moves_invoices_to_inv_and_prints_pf(): void
    {
        $this->artisan('odoo:document-numbers')->assertSuccessful();

        $this->assertSame('S00041', $this->db['sale.order'][41]['name']);
        $this->assertSame('S00043', $this->db['sale.order'][43]['name']);
        $this->assertSame('QN00042', $this->db['sale.order'][42]['name'], 'a quotation keeps QN');
        $this->assertSame('QN00044', $this->db['sale.order'][45]['name'], 'a taken number is not reused');
        $this->assertSame('INV', $this->db['account.journal'][1]['code']);

        $view = collect($this->db['ir.ui.view'])->firstWhere('name', 'HOC document numbers');
        $this->assertNotNull($view);
        $this->assertSame(500, $view['inherit_id']);
        $this->assertStringContainsString("'PF' + (doc.name[len('QN'):]", $view['arch']);
        $this->assertStringContainsString('رقم أمر البيع', $view['arch']);

        // The old origin still finds the renamed order.
        $this->assertSame(41, app(OdooClient::class)->saleOrderIdByName('QN00041'));
    }

    public function test_the_first_invoice_after_the_switch_continues_the_count_as_inv(): void
    {
        $odoo = app(OdooClient::class);
        $odoo->useInvoicePrefix();

        $odoo->postInvoice(20);
        $this->assertSame('INV/2026/00012', $this->db['account.move'][20]['name']);
        $this->assertSame('posted', $this->db['account.move'][20]['state']);

        // From then on Odoo numbers them itself: no name is written.
        $writesBefore = count(array_filter($this->calls, fn (array $call): bool => $call === ['account.move', 'write', $call[2]]));
        $odoo->postInvoice(21);
        $writesAfter = count(array_filter($this->calls, fn (array $call): bool => $call === ['account.move', 'write', $call[2]]));
        $this->assertSame($writesBefore, $writesAfter);
        $this->assertSame('posted', $this->db['account.move'][21]['state']);
    }

    public function test_confirming_a_quotation_gives_it_the_order_number(): void
    {
        $this->assertTrue(app(OdooClient::class)->confirmSaleOrder(42));

        $this->assertSame('sale', $this->db['sale.order'][42]['state']);
        $this->assertSame('S00042', $this->db['sale.order'][42]['name']);
    }

    private function answer(Request $request): PromiseInterface
    {
        $params = $request->data()['params'] ?? [];
        if (($params['service'] ?? '') === 'common') {
            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2]);
        }
        $args = $params['args'] ?? [];
        [$model, $method, $payload] = [$args[3] ?? '', $args[4] ?? '', $args[5] ?? []];
        $this->calls[] = [$model, $method, $payload];

        $result = match ($method) {
            'search_read' => $this->search($model, $payload[0] ?? [], (int) ($payload[3] ?? 0), (string) ($payload[4] ?? '')),
            'write' => $this->write($model, (array) ($payload[0] ?? []), (array) ($payload[1] ?? [])),
            'create' => $this->create($model, $payload[0][0] ?? $payload[0] ?? []),
            'action_confirm' => $this->write('sale.order', (array) ($payload[0] ?? []), ['state' => 'sale']),
            'action_post' => $this->write('account.move', (array) ($payload[0] ?? []), ['state' => 'posted']),
            default => [],
        };

        return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
    }

    /** @return list<array<string, mixed>> */
    private function search(string $model, array $domain, int $limit, string $order): array
    {
        $rows = array_values(array_filter($this->db[$model] ?? [], fn (array $row): bool => $this->rowMatches($row, $domain)));
        if ($order !== '') {
            [$field, $direction] = array_pad(explode(' ', $order), 2, 'asc');
            usort($rows, fn (array $a, array $b): int => ($direction === 'desc' ? -1 : 1) * (($a[$field] ?? 0) <=> ($b[$field] ?? 0)));
        }

        return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
    }

    private function rowMatches(array $row, array $domain): bool
    {
        foreach ($domain as $term) {
            if (! is_array($term)) {
                continue;
            }
            [$field, $op, $value] = $term;
            $actual = $row[$field] ?? null;
            if (is_array($actual)) {
                $actual = $actual[0];
            }
            $ok = match ($op) {
                '=' => $actual === $value || ($value === false && ($actual === null || $actual === false)),
                '!=' => $actual !== $value,
                'in' => in_array($actual, (array) $value, true),
                '=like' => is_string($actual) && str_starts_with($actual, rtrim((string) $value, '%')),
                '>=' => (string) $actual >= (string) $value,
                '<=' => (string) $actual <= (string) $value,
                default => true,
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    private function write(string $model, array $ids, array $values): bool
    {
        foreach ($ids as $id) {
            $this->db[$model][(int) $id] = [...$this->db[$model][(int) $id], ...$values];
        }

        return true;
    }

    private function create(string $model, array $values): int
    {
        $id = max(array_keys($this->db[$model] ?? [0 => []])) + 1;
        $this->db[$model][$id] = ['id' => $id, ...$values];

        return $id;
    }
}
