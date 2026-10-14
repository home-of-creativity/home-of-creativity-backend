<?php

namespace Tests\Feature;

use App\Actions\SendQuotation;
use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\ServiceRequest;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The client's decisions on the bot close the matching papers in Odoo: a rejected or
 * cancelled quotation, an unpaid invoice, and never a second open quotation per request.
 */
class OdooClientDecisionTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> sales order id => state */
    private array $orders = [];

    private int $nextOrder = 880;

    private ?string $move = null;

    private bool $odooDown = false;

    /** @var list<array{0: string, 1: string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.strict' => false,
            'services.odoo.enabled' => true,
            'services.odoo.url' => 'https://odoo.test',
            'services.odoo.db' => 'hoc',
            'services.odoo.username' => 'admin',
            'services.odoo.api_key' => 'secret-key',
            'services.odoo.use_json2' => false,
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1, 'document' => ['file_id' => 'f1']]]),
            'https://odoo.test/jsonrpc' => fn (Request $request) => $this->odooAnswer($request),
        ]);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);
    }

    public function test_rejecting_cancels_the_quotation_and_the_next_one_is_a_new_sales_order(): void
    {
        $request = $this->sentQuotation();
        $this->assertSame('sent', $this->orders[880]);

        $this->bot($request, 'reject', ['reason' => 'السعر مرتفع'])->assertOk();
        $this->assertSame(RequestStatus::QuotationRejected, $request->fresh()->status);
        $this->assertSame('cancel', $this->orders[880]);

        // Sales send a new version: a new sales order, the cancelled id is not reused.
        $this->postJson("/api/admin/requests/{$request->id}/quotation", [
            'lines' => [['title' => 'شعار', 'amount' => 150, 'units' => 1]],
        ])->assertOk();
        $request->refresh();
        $this->assertSame(RequestStatus::QuotationSent, $request->status);
        $this->assertSame('881', (string) $request->odoo_quotation_id);
        $this->assertSame(2, $this->calls('sale.order', 'create'));

        // A bigger package replaces the open version instead of adding a second one.
        app(SendQuotation::class)->handle($request, 300, null, 'client:larger-package', null, [['title' => 'باقة أكبر', 'amount' => 300, 'units' => 1]], true);
        $this->assertSame('882', (string) $request->fresh()->odoo_quotation_id);
        $this->assertArrayNotHasKey(881, $this->orders);
        $this->assertSame([882], array_keys(array_filter($this->orders, fn (string $state): bool => in_array($state, ['draft', 'sent'], true))));
    }

    public function test_cancelling_before_the_invoice_cancels_the_quotation(): void
    {
        $request = $this->sentQuotation();

        $this->bot($request, 'cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('cancel', $this->orders[880]);
    }

    public function test_cancelling_with_an_unpaid_invoice_cancels_the_invoice_and_leaves_the_sales_order_to_sales(): void
    {
        $request = $this->invoicedRequest();

        $this->bot($request, 'cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $request->refresh();
        $this->assertSame('cancel', $this->move);
        $this->assertNull($request->odoo_invoice_id);
        $this->assertSame('cancelled', $request->invoices()->first()->status);
        $this->assertSame('sale', $this->orders[880]);
        $this->assertSame(1, $this->calls('sale.order', 'message_post'));
    }

    public function test_cancelling_after_payment_is_refused(): void
    {
        $request = $this->invoicedRequest();
        $this->postJson("/api/admin/requests/{$request->id}/confirm-payment", ['payment_method' => 'cash', 'amount' => 200])->assertOk();

        $this->bot($request, 'cancel')->assertStatus(422);
        $this->assertSame(RequestStatus::PaymentConfirmed, $request->fresh()->status);
        $this->assertSame('posted', $this->move);
        $this->assertSame(0, $this->calls('account.move', 'button_cancel'));
    }

    public function test_an_odoo_failure_does_not_undo_the_clients_decision(): void
    {
        $request = $this->sentQuotation();
        $this->odooDown = true;

        $this->bot($request, 'reject', ['reason' => 'غير مناسب'])->assertOk();
        $this->assertSame(RequestStatus::QuotationRejected, $request->fresh()->status);

        $this->bot($request, 'cancel')->assertOk();
        $this->assertSame(RequestStatus::Cancelled, $request->fresh()->status);
        $this->assertSame('sent', $this->orders[880]);
    }

    public function test_sales_cancel_a_confirmed_order_with_its_unpaid_invoice_but_not_a_paid_one(): void
    {
        $request = $this->invoicedRequest();
        $this->deleteJson('/api/admin/odoo/quotations/880')->assertOk()->assertJsonPath('data.result', 'cancelled');
        $request->refresh();
        $this->assertSame('cancel', $this->move);
        $this->assertSame('cancel', $this->orders[880]);
        $this->assertSame(RequestStatus::Cancelled, $request->status);
        $this->assertNull($request->odoo_invoice_id);

        $paid = $this->invoicedRequest(second: true);
        $this->postJson("/api/admin/requests/{$paid->id}/confirm-payment", ['payment_method' => 'cash', 'amount' => 200])->assertOk();
        $this->deleteJson("/api/admin/odoo/quotations/{$paid->odoo_quotation_id}")->assertStatus(422);
        $this->assertSame('sale', $this->orders[(int) $paid->odoo_quotation_id]);
    }

    private function sentQuotation(): ServiceRequest
    {
        $client = Client::query()->firstOrCreate(['telegram_user_id' => '777001'], Client::factory()->raw([
            'company_name' => 'Damastech',
            'email' => 'client@example.com',
            'odoo_partner_id' => '44',
        ]));
        $this->postJson('/api/admin/odoo/quotations', [
            'client_id' => $client->id, 'action' => 'send', 'deliver' => 'email',
            'lines' => [['title' => 'شعار', 'amount' => 200, 'units' => 1]],
        ])->assertCreated();

        return ServiceRequest::query()->latest('id')->firstOrFail();
    }

    private function invoicedRequest(bool $second = false): ServiceRequest
    {
        $request = $this->sentQuotation();
        $this->bot($request, 'approve')->assertOk();
        // The admin approves (sales order) before invoicing from the dashboard.
        app(\App\Actions\ApproveQuotation::class)->markApproved($request->fresh(), 'admin', confirmOrder: true);
        if ($second) {
            $this->move = null;
        }
        $this->postJson('/api/admin/odoo/invoices', [
            'client_id' => $request->client_id, 'quotation_id' => (int) $request->odoo_quotation_id, 'action' => 'post', 'deliver' => 'email',
        ])->assertCreated();
        $request->refresh();
        $this->assertSame('990', (string) $request->odoo_invoice_id);

        return $request;
    }

    /** @param  array<string, mixed>  $data */
    private function bot(ServiceRequest $request, string $action, array $data = []): TestResponse
    {
        return $this->withHeaders(['X-Webhook-Secret' => (string) config('services.telegram.bot_secret')])
            ->postJson("/api/bot/telegram/requests/{$request->number}/{$action}", ['telegram_user_id' => '777001'] + $data);
    }

    private function calls(string $model, string $method): int
    {
        return count(array_filter($this->calls, fn (array $call): bool => $call === [$model, $method]));
    }

    private function odooAnswer(Request $request): PromiseInterface
    {
        $params = $request->data()['params'] ?? [];
        if (($params['service'] ?? '') === 'common') {
            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2]);
        }
        if ($this->odooDown) {
            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['message' => 'Odoo is down', 'data' => ['message' => 'Odoo is down']]]);
        }
        $args = $params['args'] ?? [];
        [$model, $method, $payload] = [$args[3] ?? '', $args[4] ?? '', $args[5] ?? []];
        $this->calls[] = [$model, $method];
        $ids = array_map('intval', (array) ($payload[0] ?? []));

        $result = match (true) {
            $model === 'sale.order' && $method === 'create' => $this->createOrder(),
            $model === 'sale.order' && $method === 'action_quotation_sent' => $this->setOrders($ids, 'sent', ['draft']),
            $model === 'sale.order' && $method === 'action_confirm' => $this->setOrders($ids, 'sale'),
            $model === 'sale.order' && $method === 'action_cancel' => $this->setOrders($ids, 'cancel'),
            $model === 'sale.order' && $method === 'unlink' => $this->unlinkOrders($ids),
            $model === 'sale.order' && $method === 'search_read' => $this->orderRows((array) ($payload[0] ?? [])),
            $model === 'sale.order.line' && $method === 'search_read' => [['name' => 'شعار', 'product_uom_qty' => 1, 'price_unit' => 200, 'discount' => 0, 'display_type' => false, 'product_id' => false]],
            $model === 'account.move' && $method === 'create' => $this->setMove('draft', 990),
            $model === 'account.move' && $method === 'action_post' => $this->setMove('posted'),
            $model === 'account.move' && $method === 'button_draft' => $this->setMove('draft'),
            $model === 'account.move' && $method === 'button_cancel' => $this->setMove('cancel'),
            $model === 'account.move' && $method === 'search_read' => $this->move === null ? [] : [[
                'id' => 990, 'name' => 'INV/2026/0090', 'partner_id' => [44, 'Damastech'], 'amount_total' => 200,
                'amount_residual' => 200, 'currency_id' => [1, 'USD'], 'state' => $this->move,
                'payment_state' => 'not_paid', 'invoice_origin' => 'S00880', 'ref' => 'DASH', 'invoice_date' => '2026-10-12',
            ]],
            default => [],
        };

        return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
    }

    private function createOrder(): int
    {
        $id = $this->nextOrder++;
        $this->orders[$id] = 'draft';

        return $id;
    }

    /**
     * @param  list<int>  $ids
     * @param  list<string>|null  $from
     */
    private function setOrders(array $ids, string $state, ?array $from = null): bool
    {
        foreach ($ids as $id) {
            if (isset($this->orders[$id]) && ($from === null || in_array($this->orders[$id], $from, true))) {
                $this->orders[$id] = $state;
            }
        }

        return true;
    }

    /** @param  list<int>  $ids */
    private function unlinkOrders(array $ids): bool
    {
        foreach ($ids as $id) {
            unset($this->orders[$id]);
        }

        return true;
    }

    /**
     * @param  array<int, mixed>  $domain
     * @return list<array<string, mixed>>
     */
    private function orderRows(array $domain): array
    {
        $wanted = null;
        foreach ($domain as $term) {
            if (is_array($term) && ($term[0] ?? null) === 'id') {
                $wanted = array_map('intval', (array) $term[2]);
            }
        }

        $rows = [];
        foreach ($this->orders as $id => $state) {
            if ($wanted !== null && ! in_array($id, $wanted, true)) {
                continue;
            }
            $rows[] = [
                'id' => $id, 'name' => "S00{$id}", 'partner_id' => [44, 'Damastech'], 'amount_total' => 200,
                'currency_id' => [1, 'USD'], 'state' => $state, 'client_order_ref' => 'DASH',
                'invoice_ids' => $this->move !== null ? [990] : [],
            ];
        }

        return $rows;
    }

    private function setMove(string $state, mixed $result = true): mixed
    {
        $this->move = $state;

        return $result;
    }
}
