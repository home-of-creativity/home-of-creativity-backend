<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\ServiceRequest;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
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

        // The client approves on the bot; the quotation becomes a sales order once an admin confirms.
        $this->withHeaders(['X-Webhook-Secret' => (string) config('services.telegram.bot_secret')])
            ->postJson("/api/bot/telegram/requests/{$request->number}/approve", ['telegram_user_id' => '777001'])
            ->assertOk();
        $this->assertSame(RequestStatus::AwaitingAdminApproval, $request->fresh()->status);
        $this->assertNotSame('sale', $this->odoo['order']);
        $this->postJson("/api/admin/requests/{$request->id}/client-approval", ['decision' => 'confirm'])->assertOk();
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

    public function test_a_quotation_may_carry_enough_lines_for_more_than_two_pages(): void
    {
        $client = $this->botClient();
        $lines = [];
        for ($index = 1; $index <= 21; $index++) {
            $lines[] = ['title' => 'بند '.$index, 'amount' => 10, 'units' => 1];
        }

        $this->postJson('/api/admin/odoo/quotations', [
            'client_id' => $client->id, 'action' => 'draft', 'lines' => $lines,
        ])->assertCreated();

        $tooMany = $lines;
        for ($index = 22; $index <= 81; $index++) {
            $tooMany[] = ['title' => 'بند '.$index, 'amount' => 10, 'units' => 1];
        }
        $this->postJson('/api/admin/odoo/quotations', [
            'client_id' => $client->id, 'action' => 'draft', 'lines' => $tooMany,
        ])->assertStatus(422)->assertJsonValidationErrors('lines');
    }

    public function test_quotation_lines_carry_odoo_taxes_and_payment_terms(): void
    {
        $this->getJson('/api/admin/odoo/taxes')->assertOk()->assertJsonPath('data.0.id', 7)->assertJsonPath('data.0.amount', 5);
        // Terms Odoo has only in English arrive in Arabic.
        $this->getJson('/api/admin/odoo/payment-terms')->assertOk()->assertJsonPath('data.0.name', 'خلال 30 يوماً');

        $client = $this->botClient();
        $this->postJson('/api/admin/odoo/quotations', [
            'client_id' => $client->id, 'action' => 'send', 'deliver' => 'email', 'payment_term_id' => 3,
            'lines' => [['title' => 'شعار', 'amount' => 200, 'units' => 1, 'tax_ids' => [7]]],
        ])->assertCreated();

        $create = $this->payload('sale.order', 'create');
        $this->assertSame([[6, 0, [7]]], $create[0][0]['order_line'][0][2]['tax_ids']);
        $this->assertTrue(collect($this->odoo['payloads'])->contains(
            fn (array $call): bool => $call[0] === 'sale.order' && $call[1] === 'write' && ($call[2][1]['payment_term_id'] ?? null) === 3,
        ));
        // Odoo's total (300 in this fake) is what the client is asked to pay, tax included.
        $request = ServiceRequest::query()->sole();
        $this->assertSame(300.0, (float) $request->quotation_amount);
        $this->assertStringContainsString('الضرائب', (string) $request->quotation_notes);

        // Editing keeps the tax and can clear the term.
        $this->putJson('/api/admin/odoo/quotations/880', [
            'payment_term_id' => 0,
            'lines' => [['title' => 'شعار', 'amount' => 200, 'units' => 1, 'tax_ids' => [7]]],
        ])->assertOk();
        $write = collect($this->odoo['payloads'])->last(fn (array $call): bool => $call[0] === 'sale.order' && $call[1] === 'write');
        $this->assertFalse($write[2][1]['payment_term_id']);
        $this->assertSame([[6, 0, [7]]], $write[2][1]['order_line'][1][2]['tax_ids']);
    }

    /** @return array<int, mixed> */
    private function payload(string $model, string $method): array
    {
        return collect($this->odoo['payloads'])->first(fn (array $call): bool => $call[0] === $model && $call[1] === $method)[2];
    }

    public function test_the_invoice_wizard_makes_a_named_down_payment_draft_then_confirm_posts_it(): void
    {
        $client = $this->botClient();
        $this->postJson('/api/admin/odoo/quotations', [
            'client_id' => $client->id, 'action' => 'send', 'deliver' => 'email',
            'lines' => [['title' => 'باقة إنستغرام شهرية', 'amount' => 300, 'units' => 1]],
        ])->assertCreated();
        $request = ServiceRequest::query()->sole();
        $this->getJson('/api/admin/odoo/quotations/880')->assertOk()->assertJsonPath('data.invoiced', 0);

        $this->postJson('/api/admin/odoo/quotations/880/invoice', ['method' => 'percentage', 'amount' => 120])->assertUnprocessable();
        $this->postJson('/api/admin/odoo/quotations/880/invoice', ['method' => 'percentage'])->assertUnprocessable();

        $this->odoo['order_lines'] = [['name' => "باقة إنستغرام شهرية\nتصميم ونشر", 'product_uom_qty' => 1, 'price_unit' => 300, 'discount' => 0, 'display_type' => false, 'product_id' => false]];
        $draft = $this->postJson('/api/admin/odoo/quotations/880/invoice', ['method' => 'percentage', 'amount' => 50])
            ->assertCreated()
            ->assertJsonPath('data.id', 990)
            ->assertJsonPath('data.state', 'draft')
            ->assertJsonPath('data.quotation_id', 880)
            ->json('data');
        $this->assertSame('draft', $this->odoo['move']);

        // The quotation was confirmed first, and the wizard ran on it with a 50% down payment.
        $this->assertSame('sale', $this->odoo['order']);
        $wizard = collect($this->odoo['payloads'])->first(fn (array $call): bool => $call[0] === 'sale.advance.payment.inv' && $call[1] === 'create');
        $values = $wizard[2][0][0] ?? $wizard[2][0];
        $this->assertSame('percentage', $values['advance_payment_method']);
        $this->assertEquals(50, $values['amount']);
        $rename = collect($this->odoo['payloads'])->last(fn (array $call): bool => $call[0] === 'account.move' && $call[1] === 'write');
        $this->assertSame('باقة إنستغرام شهرية — دفعة مقدّمة 50%', $rename[2][1]['invoice_line_ids'][0][2]['name']);
        $this->assertSame(RequestStatus::QuotationSent, $request->fresh()->status);

        // «تأكيد»: posted, recorded on the request, and the request waits for payment.
        $this->postJson("/api/admin/odoo/invoices/{$draft['id']}/post", ['deliver' => 'email', 'quotation_id' => 880])->assertOk();
        $this->assertSame('posted', $this->odoo['move']);
        $request->refresh();
        $this->assertSame(RequestStatus::AwaitingPayment, $request->status);
        $this->assertSame('990', (string) $request->odoo_invoice_id);
        $this->assertSame(1, ServiceRequest::query()->count());
        $this->postJson("/api/admin/odoo/invoices/{$draft['id']}/post", ['deliver' => 'email'])->assertUnprocessable();
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

    private function odooAnswer(Request $request): PromiseInterface
    {
        $params = $request->data()['params'] ?? [];
        if (($params['service'] ?? '') === 'common') {
            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2]);
        }
        $args = $params['args'] ?? [];
        [$model, $method, $payload] = [$args[3] ?? '', $args[4] ?? '', $args[5] ?? []];
        $this->odoo['calls'][] = [$model, $method];
        $this->odoo['payloads'][] = [$model, $method, $payload];
        $result = match (true) {
            $model === 'sale.order' && $method === 'create' => 880,
            $model === 'sale.order' && $method === 'action_quotation_sent' => $this->setOrder($this->odoo['order'] === 'draft' ? 'sent' : $this->odoo['order']),
            $model === 'sale.order' && $method === 'action_confirm' => $this->setOrder('sale'),
            $model === 'sale.order' && $method === 'action_cancel' => $this->setOrder('cancel'),
            $model === 'sale.order' && $method === 'write' => $this->writeOrderLines($payload),
            $model === 'sale.order' && $method === 'search_read' => [[
                'id' => 880, 'name' => 'S00880', 'partner_id' => [44, 'Damastech'], 'amount_total' => 300,
                'currency_id' => [1, 'USD'], 'state' => $this->odoo['order'], 'client_order_ref' => 'DASH',
                'invoice_ids' => $this->odoo['move'] === null ? [] : [990],
            ]],
            // Odoo's "Create invoice" wizard: a draft invoice linked to the order.
            $model === 'sale.advance.payment.inv' && $method === 'create' => [77],
            $model === 'sale.advance.payment.inv' && $method === 'create_invoices' => $this->setMove('draft', ['type' => 'ir.actions.act_window']),
            $model === 'account.move.line' && $method === 'search_read' => [['id' => 5, 'name' => 'Down payment of 50%', 'quantity' => 1, 'price_unit' => 150, 'discount' => 0, 'display_type' => 'product', 'product_id' => [9, 'Down payment'], 'tax_ids' => []]],
            $model === 'account.tax' && $method === 'search_read' => [['id' => 7, 'name' => 'VAT 5%', 'amount' => 5, 'amount_type' => 'percent', 'price_include' => false]],
            $model === 'account.payment.term' && $method === 'search_read' => [['id' => 3, 'name' => '30 Days']],
            $model === 'sale.order.line' && $method === 'search_read' => $this->odoo['order_lines'] ?: [['name' => 'شعار', 'product_uom_qty' => 1, 'price_unit' => 200, 'discount' => 0, 'display_type' => false, 'product_id' => false]],
            $model === 'account.move' && $method === 'create' => $this->setMove('draft', 990),
            $model === 'account.move' && $method === 'action_post' => $this->setMove('posted'),
            $model === 'account.move' && $method === 'button_draft' => $this->setMove('draft'),
            $model === 'account.move' && $method === 'button_cancel' => $this->setMove('cancel'),
            $model === 'account.move' && $method === 'search_read' => [[
                'id' => 990, 'name' => 'INV/2026/0090', 'partner_id' => [44, 'Damastech'], 'amount_total' => 280,
                'amount_residual' => 280, 'currency_id' => [1, 'USD'], 'state' => $this->odoo['move'] ?? 'draft',
                'payment_state' => 'not_paid', 'invoice_origin' => 'S00880', 'ref' => 'DASH', 'invoice_date' => '2026-10-12',
                'move_type' => 'out_invoice',
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
