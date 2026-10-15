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
                11 => [
                    'id' => 11, 'name' => 'الفات/2026/00011', 'state' => 'posted', 'move_type' => 'out_invoice', 'journal_id' => [1, 'Sales'],
                    'invoice_date' => '2026-10-10', 'invoice_date_due' => '2026-10-31', 'sequence_number' => 11, 'payment_reference' => 'الفات/2026/00011',
                    'invoice_payment_term_id' => [3, 'دفع فوري'], 'payment_state' => 'not_paid', 'invoice_pdf_report_id' => false,
                ],
                12 => ['id' => 12, 'name' => 'Rالفات/2026/00001', 'state' => 'posted', 'move_type' => 'out_refund', 'journal_id' => [1, 'Sales'], 'invoice_date' => '2026-10-11', 'sequence_number' => 1, 'invoice_pdf_report_id' => [900, 'R.pdf']],
                20 => ['id' => 20, 'name' => false, 'state' => 'draft', 'move_type' => 'out_invoice', 'journal_id' => [1, 'Sales'], 'invoice_date' => '2026-10-14', 'sequence_number' => 0],
                21 => ['id' => 21, 'name' => false, 'state' => 'draft', 'move_type' => 'out_invoice', 'journal_id' => [1, 'Sales'], 'invoice_date' => '2026-10-14', 'sequence_number' => 0],
            ],
            'account.move.line' => [90 => ['id' => 90, 'move_id' => [11, 'الفات/2026/00011'], 'display_type' => 'payment_term', 'date_maturity' => '2026-10-31']],
            'account.payment.term' => [3 => ['id' => 3, 'name' => 'دفع فوري', 'note' => '<p>Payment terms: 21 Days</p>']],
            'account.payment.term.line' => [7 => ['id' => 7, 'payment_id' => [3, 'دفع فوري'], 'nb_days' => 21]],
            'ir.ui.view' => [
                500 => ['id' => 500, 'key' => 'sale.report_saleorder_document', 'inherit_id' => false, 'name' => 'report_saleorder_document'],
                501 => ['id' => 501, 'key' => 'account.report_invoice_document', 'inherit_id' => false, 'name' => 'report_invoice_document'],
            ],
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
            ->expectsOutputToContain('invoice الفات/2026/00011 → INV/2026/00011 (dry run)')
            ->expectsOutputToContain('invoice Rالفات/2026/00001 → RINV/2026/00001 (dry run)')
            ->expectsOutputToContain('invoices issued as invoices, not proforma: 1 (dry run)')
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

    public function test_earlier_invoices_take_inv_print_as_invoices_and_immediate_is_due_at_once(): void
    {
        $this->artisan('odoo:document-numbers')->assertSuccessful();

        $invoice = $this->db['account.move'][11];
        $this->assertSame('INV/2026/00011', $invoice['name']);
        $this->assertSame('INV/2026/00011', $invoice['payment_reference']);
        $this->assertSame('RINV/2026/00001', $this->db['account.move'][12]['name']);
        $this->assertSame([950, 'invoice.pdf'], $invoice['invoice_pdf_report_id'], 'a posted invoice without its PDF prints as proforma');
        $this->assertSame([900, 'R.pdf'], $this->db['account.move'][12]['invoice_pdf_report_id'], 'an issued PDF is left alone');
        $wizard = collect($this->db['account.move.send.wizard'] ?? [])->first();
        $this->assertSame([], $wizard['sending_methods'], 'nothing is emailed from Odoo');

        $this->assertSame(0, $this->db['account.payment.term.line'][7]['nb_days']);
        $this->assertSame('2026-10-10', $this->db['account.move.line'][90]['date_maturity']);
        $this->assertSame('2026-10-10', $invoice['invoice_date_due']);

        $view = collect($this->db['ir.ui.view'])->firstWhere('name', 'HOC invoice without payment communication');
        $this->assertSame(501, $view['inherit_id']);
        $this->assertStringContainsString("payment_communication']/..", $view['arch']);
    }

    public function test_the_first_invoice_after_the_switch_continues_the_count_as_inv(): void
    {
        $odoo = app(OdooClient::class);
        $odoo->useInvoicePrefix();

        $odoo->postInvoice(20);
        $this->assertSame('INV/2026/00012', $this->db['account.move'][20]['name']);
        $this->assertSame('posted', $this->db['account.move'][20]['state']);
        $this->assertTrue($this->db['account.move'][20]['is_move_sent'], 'a posted invoice is issued, not left as proforma');

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
            // «Send & Print» with no sending method: the issued PDF only.
            'action_send_and_print' => $this->write('account.move', (array) ($args[6]['context']['active_ids'] ?? []), ['invoice_pdf_report_id' => [950, 'invoice.pdf'], 'is_move_sent' => true]),
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
        $domain = array_values($domain);
        for ($index = 0; $index < count($domain); $index++) {
            if ($domain[$index] === '|') {
                if (! $this->rowMatches($row, [$domain[$index + 1]]) && ! $this->rowMatches($row, [$domain[$index + 2]])) {
                    return false;
                }
                $index += 2;

                continue;
            }
            $term = $domain[$index];
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
