<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Dashboard quotations and invoices against a small in-memory Odoo: every add, edit,
 * and delete reaches Odoo and moves the request and the bot conversation with it.
 */
class OdooPaperFlowTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $odoo = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'test-token',
            'services.odoo.enabled' => true,
            'services.odoo.url' => 'https://odoo.test',
            'services.odoo.db' => 'hoc',
            'services.odoo.username' => 'admin',
            'services.odoo.api_key' => 'secret-key',
            'services.odoo.use_json2' => false,
        ]);
        $this->odoo = ['order' => 'draft', 'move' => null, 'order_lines' => [], 'move_lines' => [], 'calls' => []];
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1, 'document' => ['file_id' => 'f1']]]),
            'https://odoo.test/jsonrpc' => fn (Request $request) => $this->odooAnswer($request),
        ]);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);
    }

    public function test_quotation_edit_approval_sales_order_invoice_edit_and_payment_run_as_one_flow(): void
    {
        $client = $this->botClient();

        // Add: the quotation opens a request waiting for the client.
        $this->postJson('/api/admin/odoo/quotations', [
            'client_id' => $client->id, 'action' => 'send', 'deliver' => 'email',
            'lines' => [['title' => 'شعار', 'amount' => 200, 'units' => 1]],
        ])->assertCreated();
        $request = ServiceRequest::query()->sole();
        $this->assertSame(RequestStatus::QuotationSent, $request->status);

        // Edit: Odoo gets the new lines and the client gets version 2 with the buttons.
        $this->getJson('/api/admin/odoo/quotations/880')->assertOk()->assertJsonPath('data.lines.0.title', 'شعار')->assertJsonPath('data.request.number', $request->number);
        $this->putJson('/api/admin/odoo/quotations/880', [
            'lines' => [['title' => 'شعار', 'amount' => 200, 'units' => 1], ['title' => 'بطاقات', 'amount' => 50, 'units' => 2]],
        ])->assertOk();
        $request->refresh();
        $this->assertSame(RequestStatus::QuotationSent, $request->status);
        $this->assertSame(2, $request->quotations()->count());
        $this->assertSame(300.0, (float) $request->quotation_amount);
        $this->assertSame(1, $this->calls('sale.order', 'write'));

        // The client approves on the bot: the quotation becomes a sales order.
        $this->withHeaders(['X-Webhook-Secret' => (string) config('services.telegram.bot_secret')])
            ->postJson("/api/bot/telegram/requests/{$request->number}/approve", ['telegram_user_id' => '777001'])
            ->assertOk();
        $this->assertSame(RequestStatus::AwaitingPayment, $request->fresh()->status);
        $this->assertSame('sale', $this->odoo['order']);
        $this->putJson('/api/admin/odoo/quotations/880', ['lines' => [['title' => 'x', 'amount' => 1]]])->assertStatus(422);

        // Invoice from the sales order: same request, sent on the bot.
        $this->postJson('/api/admin/odoo/invoices', ['client_id' => $client->id, 'quotation_id' => 880, 'action' => 'post', 'deliver' => 'email'])->assertCreated();
        $this->assertSame(1, ServiceRequest::query()->count());
        $this->assertSame('990', (string) $request->fresh()->odoo_invoice_id);

        // Edit the unpaid invoice: back to draft, new lines, posted again, sent again.
        $this->putJson('/api/admin/odoo/invoices/990', ['lines' => [['title' => 'شعار وبطاقات', 'amount' => 280, 'units' => 1]]])->assertOk();
        $this->assertSame(1, $this->calls('account.move', 'button_draft'));
        $this->assertSame('posted', $this->odoo['move']);
        $this->assertSame(280.0, (float) $request->fresh()->amount_total);

        // Payment goes on that invoice; no second invoice in Odoo.
        $this->postJson("/api/admin/requests/{$request->id}/confirm-payment", ['payment_method' => 'cash', 'amount' => 280])->assertOk();
        $this->assertSame(1, $this->calls('account.move', 'create'));
        $this->assertSame(RequestStatus::PaymentConfirmed, $request->fresh()->status);
    }

    public function test_deleting_a_sent_quotation_cancels_its_request_and_deleting_an_invoice_keeps_payment_open(): void
    {
        $client = $this->botClient();
        $this->postJson('/api/admin/odoo/quotations', [
            'client_id' => $client->id, 'action' => 'send', 'deliver' => 'email',
            'lines' => [['title' => 'شعار', 'amount' => 200, 'units' => 1]],
        ])->assertCreated();
        $request = ServiceRequest::query()->sole();

        $this->deleteJson('/api/admin/odoo/quotations/880')->assertOk()->assertJsonPath('data.result', 'cancelled');
        $this->assertSame('cancel', $this->odoo['order']);
        $this->assertSame(RequestStatus::Cancelled, $request->fresh()->status);
        Http::assertSent(fn (Request $sent): bool => str_contains($sent->url(), 'api.telegram.org')
            && str_contains(json_encode($sent->data(), JSON_UNESCAPED_UNICODE), 'أُلغي عرض السعر'));

        // A standalone invoice: posted, then cancelled. Its request waits for a correct one.
        $this->postJson('/api/admin/odoo/invoices', [
            'client_id' => $client->id, 'action' => 'post', 'deliver' => 'email',
            'lines' => [['title' => 'تصوير', 'amount' => 150, 'units' => 1]],
        ])->assertCreated();
        $second = ServiceRequest::query()->latest('id')->first();
        $this->assertSame(RequestStatus::AwaitingPayment, $second->status);

        $this->deleteJson('/api/admin/odoo/invoices/990')->assertOk();
        $this->assertSame('cancel', $this->odoo['move']);
        $second->refresh();
        $this->assertNull($second->odoo_invoice_id);
        $this->assertSame(RequestStatus::AwaitingPayment, $second->status);
        $this->assertSame('cancelled', $second->invoices()->first()->status);
    }

    private function botClient(): Client
    {
        return Client::factory()->create([
            'company_name' => 'Damastech',
            'email' => 'client@example.com',
            'odoo_partner_id' => '44',
            'telegram_user_id' => '777001',
        ]);
    }

    private function calls(string $model, string $method): int
    {
        return count(array_filter($this->odoo['calls'], fn (array $call): bool => $call === [$model, $method]));
    }

    private function odooAnswer(Request $request): \GuzzleHttp\Promise\PromiseInterface
    {
        $params = $request->data()['params'] ?? [];
        if (($params['service'] ?? '') === 'common') {
            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2]);
        }
        $args = $params['args'] ?? [];
        [$model, $method, $payload] = [$args[3] ?? '', $args[4] ?? '', $args[5] ?? []];
        $this->odoo['calls'][] = [$model, $method];
        $result = match (true) {
            $model === 'sale.order' && $method === 'create' => 880,
            $model === 'sale.order' && $method === 'action_quotation_sent' => $this->setOrder($this->odoo['order'] === 'draft' ? 'sent' : $this->odoo['order']),
            $model === 'sale.order' && $method === 'action_confirm' => $this->setOrder('sale'),
            $model === 'sale.order' && $method === 'action_cancel' => $this->setOrder('cancel'),
            $model === 'sale.order' && $method === 'write' => $this->writeOrderLines($payload),
            $model === 'sale.order' && $method === 'search_read' => [[
                'id' => 880, 'name' => 'S00880', 'partner_id' => [44, 'Damastech'], 'amount_total' => 300,
                'currency_id' => [1, 'USD'], 'state' => $this->odoo['order'], 'client_order_ref' => 'DASH',
            ]],
            $model === 'sale.order.line' && $method === 'search_read' => $this->odoo['order_lines'] ?: [['name' => 'شعار', 'product_uom_qty' => 1, 'price_unit' => 200, 'discount' => 0, 'display_type' => false, 'product_id' => false]],
            $model === 'account.move' && $method === 'create' => $this->setMove('draft', 990),
            $model === 'account.move' && $method === 'action_post' => $this->setMove('posted'),
            $model === 'account.move' && $method === 'button_draft' => $this->setMove('draft'),
            $model === 'account.move' && $method === 'button_cancel' => $this->setMove('cancel'),
            $model === 'account.move' && $method === 'search_read' => [[
                'id' => 990, 'name' => 'INV/2026/0090', 'partner_id' => [44, 'Damastech'], 'amount_total' => 280,
                'amount_residual' => 280, 'currency_id' => [1, 'USD'], 'state' => $this->odoo['move'] ?? 'draft',
                'payment_state' => 'not_paid', 'invoice_origin' => 'S00880', 'ref' => 'DASH', 'invoice_date' => '2026-10-12',
            ]],
            default => [],
        };

        return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
    }

    private function setOrder(string $state): bool
    {
        $this->odoo['order'] = $state;

        return true;
    }

    private function setMove(string $state, mixed $result = true): mixed
    {
        $this->odoo['move'] = $state;

        return $result;
    }

    /** @param  array<int, mixed>  $payload */
    private function writeOrderLines(array $payload): bool
    {
        $lines = $payload[1]['order_line'] ?? [];
        $this->odoo['order_lines'] = array_values(array_map(fn (array $op): array => [
            'name' => $op[2]['name'] ?? '', 'product_uom_qty' => $op[2]['product_uom_qty'] ?? 1, 'price_unit' => $op[2]['price_unit'] ?? 0,
            'discount' => 0, 'display_type' => false, 'product_id' => false,
        ], array_filter($lines, fn ($op): bool => is_array($op) && ($op[0] ?? null) === 0)));

        return true;
    }
}
