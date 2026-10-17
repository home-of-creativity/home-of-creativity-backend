<?php

namespace Tests\Feature;

use App\Actions\ApproveQuotation;
use App\Actions\ConfirmRequestPayment;
use App\Enums\PaymentMethod;
use App\Enums\RequestStatus;
use App\Mail\ClientDocumentMail;
use App\Models\Client;
use App\Models\OdooInvoice;
use App\Models\Role;
use App\Models\ServiceRequest;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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

        // The client approves on the bot and is asked to pay; the admin's approval makes the sales order.
        $this->withHeaders(['X-Webhook-Secret' => (string) config('services.telegram.bot_secret')])
            ->postJson("/api/bot/telegram/requests/{$request->number}/approve", ['telegram_user_id' => '777001'])
            ->assertOk();
        $this->assertSame(RequestStatus::AwaitingAdminApproval, $request->fresh()->status);
        $this->assertNotSame('sale', $this->odoo['order']);
        app(ApproveQuotation::class)->markApproved($request->fresh(), 'admin', confirmOrder: true);
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

        // Payment goes on that invoice; no second invoice in Odoo, one local row for it.
        $this->postJson("/api/admin/requests/{$request->id}/confirm-payment", ['payment_method' => 'cash', 'amount' => 280])
            ->assertOk()
            ->assertJsonPath('invoice.number', 'INV/2026/0090')
            ->assertJsonPath('invoice.state', 'posted');
        $this->assertSame(0, $this->calls('account.move', 'create'));
        $this->assertSame(1, $this->calls('sale.advance.payment.inv', 'create_invoices'));
        $this->assertSame(RequestStatus::PaymentConfirmed, $request->fresh()->status);
        $this->assertSame(1, $request->invoices()->where('odoo_invoice_id', '990')->count());
    }

    public function test_deleting_a_sent_quotation_cancels_its_request_and_deleting_an_invoice_keeps_payment_open(): void
    {
        $client = $this->botClient();
        $this->postJson('/api/admin/odoo/quotations', [
            'client_id' => $client->id, 'action' => 'send', 'deliver' => 'email',
            'lines' => [['title' => 'شعار', 'amount' => 200, 'units' => 1]],
        ])->assertCreated();
        $request = ServiceRequest::query()->sole();

        // Deleting needs the cancel first, as in Odoo.
        $this->deleteJson('/api/admin/odoo/quotations/880')->assertStatus(422);
        $this->postJson('/api/admin/odoo/quotations/880/cancel')->assertOk()->assertJsonPath('data.result', 'cancelled');
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

        $this->deleteJson('/api/admin/odoo/invoices/990')->assertStatus(422);
        $this->postJson('/api/admin/odoo/invoices/990/cancel')->assertOk();
        $this->assertSame('cancel', $this->odoo['move']);
        $second->refresh();
        $this->assertNull($second->odoo_invoice_id);
        $this->assertSame(RequestStatus::AwaitingPayment, $second->status);
        $this->assertSame('cancelled', $second->invoices()->first()->status);
    }

    public function test_a_cancelled_quotation_is_deleted_with_its_unpaid_request_and_the_number_comes_back(): void
    {
        $client = $this->botClient();
        $this->postJson('/api/admin/odoo/quotations', [
            'client_id' => $client->id, 'action' => 'send', 'deliver' => 'email',
            'lines' => [['title' => 'شعار', 'amount' => 200, 'units' => 1]],
        ])->assertCreated();
        $request = ServiceRequest::query()->sole();

        $this->postJson('/api/admin/odoo/quotations/880/cancel')->assertOk();
        $this->postJson('/api/admin/odoo/quotations/880/cancel')->assertStatus(422);

        // QN00041 and S00042 stay in Odoo, so the next quotation is QN00043 again.
        $this->odoo['names'] = [['id' => 1, 'name' => 'QN00041'], ['id' => 2, 'name' => 'S00042']];
        $this->deleteJson('/api/admin/odoo/quotations/880')
            ->assertOk()
            ->assertJsonPath('data.result', 'deleted')
            ->assertJsonPath('data.requests.deleted.0', $request->number)
            ->assertJsonPath('data.next_number', 'QN00043');

        $this->assertSame('gone', $this->odoo['order']);
        $this->assertNull($request->fresh());
        $write = collect($this->odoo['payloads'])->first(fn (array $call): bool => $call[0] === 'ir.sequence' && $call[1] === 'write');
        $this->assertSame(['number_next_actual' => 43], $write[2][1] ?? null);
    }

    public function test_an_invoice_goes_back_to_draft_then_is_deleted_and_leaves_finance_at_once(): void
    {
        $client = $this->botClient();
        $this->postJson('/api/admin/odoo/invoices', [
            'client_id' => $client->id, 'action' => 'post', 'deliver' => 'email',
            'lines' => [['title' => 'تصوير', 'amount' => 150, 'units' => 1]],
        ])->assertCreated();
        $request = ServiceRequest::query()->sole();
        $this->assertSame('posted', OdooInvoice::query()->where('odoo_id', 990)->value('state'), 'finance has it without a sync');

        $this->deleteJson('/api/admin/odoo/invoices/990')->assertStatus(422);
        $this->postJson('/api/admin/odoo/invoices/990/draft')->assertOk()->assertJsonPath('data.result', 'draft');
        $this->assertSame('draft', $this->odoo['move']);
        $this->assertSame('draft', OdooInvoice::query()->where('odoo_id', 990)->value('state'));

        $this->deleteJson('/api/admin/odoo/invoices/990')->assertOk()->assertJsonPath('data.result', 'deleted');
        $this->assertSame('gone', $this->odoo['move']);
        $this->assertFalse(OdooInvoice::query()->where('odoo_id', 990)->exists());
        $this->assertSame(0, $request->invoices()->count());
        $this->assertNull($request->fresh()->odoo_invoice_id);
        $this->getJson('/api/admin/finance')->assertOk()->assertJsonPath('data.meta.total', 0);
    }

    public function test_a_paid_invoice_does_not_go_back_to_draft_and_deleting_is_its_own_permission(): void
    {
        $client = $this->botClient();
        $this->postJson('/api/admin/odoo/invoices', [
            'client_id' => $client->id, 'action' => 'post', 'deliver' => 'email',
            'lines' => [['title' => 'تصوير', 'amount' => 150, 'units' => 1]],
        ])->assertCreated();
        $this->odoo['paid'][990] = true;
        $this->postJson('/api/admin/odoo/invoices/990/draft')->assertStatus(422);
        $this->assertSame('posted', $this->odoo['move']);

        $role = Role::query()->create(['name' => 'Sales', 'abilities' => [
            'ops.clients.view',
            'ops.clients.odoo',
            'ops.clients.invoices_update',
            'ops.clients.quotations_view',
            'ops.clients.invoices_view',
        ]]);
        $staff = User::factory()->create(['is_admin' => false, 'role_id' => $role->id]);
        Sanctum::actingAs($staff);
        $this->postJson('/api/admin/odoo/invoices/990/cancel')->assertStatus(422);
        $this->deleteJson('/api/admin/odoo/invoices/990')->assertForbidden();
        $this->deleteJson('/api/admin/odoo/quotations/880')->assertForbidden();
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

    public function test_products_carry_their_sales_description(): void
    {
        $this->getJson('/api/admin/odoo/products')->assertOk()
            ->assertJsonPath('data.0.id', 12)
            ->assertJsonPath('data.0.description', "12 تصميم\n4 ريلز");
    }

    public function test_quotation_terms_are_html_without_the_title_or_line_list_in_front(): void
    {
        $client = $this->botClient();
        $terms = '<p><strong>الدفع</strong> خلال 7 أيام</p><script>alert(1)</script>';
        $this->postJson('/api/admin/odoo/quotations', [
            'client_id' => $client->id, 'action' => 'send', 'deliver' => 'email', 'notes' => $terms,
            'lines' => [['title' => 'شعار', 'amount' => 200, 'units' => 1]],
        ])->assertCreated();

        $create = $this->payload('sale.order', 'create')[0][0];
        $this->assertSame('<p><strong>الدفع</strong> خلال 7 أيام</p>', $create['note']);

        // The quotation detail gives the terms back for editing; an edit writes them as they are.
        $this->odoo['note'] = $create['note'];
        $this->getJson('/api/admin/odoo/quotations/880')->assertOk()->assertJsonPath('data.notes', $create['note']);
        $this->putJson('/api/admin/odoo/quotations/880', [
            'notes' => '<ul><li>بند جديد</li></ul>',
            'lines' => [['title' => 'شعار', 'amount' => 200, 'units' => 1]],
        ])->assertOk();
        $write = collect($this->odoo['payloads'])->last(fn (array $call): bool => $call[0] === 'sale.order' && $call[1] === 'write');
        $this->assertSame('<ul><li>بند جديد</li></ul>', $write[2][1]['note']);

        // Without terms the note is left to Odoo's defaults.
        $this->odoo['payloads'] = [];
        $this->postJson('/api/admin/odoo/quotations', [
            'client_id' => $client->id, 'action' => 'draft',
            'lines' => [['title' => 'بطاقات', 'amount' => 50, 'units' => 1]],
        ])->assertCreated();
        $this->assertArrayNotHasKey('note', $this->payload('sale.order', 'create')[0][0]);
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

    public function test_a_down_payment_draft_is_linked_posted_on_payment_and_the_remainder_stays_on_the_order(): void
    {
        $client = $this->botClient();
        $this->postJson('/api/admin/odoo/quotations', [
            'client_id' => $client->id, 'action' => 'send', 'deliver' => 'email',
            'lines' => [['title' => 'باقة', 'amount' => 300, 'units' => 1]],
        ])->assertCreated();
        $request = ServiceRequest::query()->sole();

        // The wizard draft becomes the request's invoice.
        $this->odoo['first_total'] = 150;
        $this->postJson('/api/admin/odoo/quotations/880/invoice', ['method' => 'percentage', 'amount' => 50])
            ->assertCreated()
            ->assertJsonPath('data.request.number', $request->number)
            ->assertJsonPath('data.client_id', $client->id);
        $this->assertSame('990', (string) $request->fresh()->odoo_invoice_id);

        // Confirming the down payment posts that draft and pays it: no second invoice, «مرحّلة».
        $request->forceFill(['status' => RequestStatus::AwaitingPayment, 'amount_total' => 300, 'amount_remaining' => 300])->save();
        $this->postJson("/api/admin/requests/{$request->id}/confirm-payment", ['payment_method' => 'cash', 'amount' => 150])
            ->assertOk()
            ->assertJsonPath('invoice.state', 'posted')
            ->assertJsonPath('invoice.error', null)
            ->assertJsonPath('message', 'تم تأكيد الدفع. الفاتورة INV/2026/0090 مرحّلة ومدفوعة.');
        $this->assertSame('posted', $this->odoo['move']);
        $this->assertSame(0, $this->calls('account.move', 'create'));
        $this->assertSame(1, $this->calls('sale.advance.payment.inv', 'create_invoices'));
        $deposit = $request->invoices()->where('odoo_invoice_id', '990')->sole();
        $this->assertSame('deposit', $deposit->kind);
        $this->assertSame('paid', $deposit->status);

        // The remainder is invoiced through the order's wizard and paid on that invoice.
        $this->postJson("/api/admin/requests/{$request->id}/confirm-payment", ['payment_method' => 'cash', 'amount' => 150])
            ->assertOk()
            ->assertJsonPath('invoice.number', 'INV/2026/0991')
            ->assertJsonPath('invoice.state', 'posted');
        $this->assertSame(2, $this->calls('sale.advance.payment.inv', 'create_invoices'));
        $this->assertSame(0, $this->calls('account.move', 'create'));
        $this->assertSame('991', (string) $request->fresh()->odoo_invoice_id);
        $this->assertSame('remaining', $request->invoices()->where('odoo_invoice_id', '991')->sole()->kind);
        $this->assertSame(0.0, (float) $request->fresh()->amount_remaining);
    }

    public function test_the_staff_bot_hears_the_invoice_number_and_state(): void
    {
        $client = $this->botClient();
        $this->postJson('/api/admin/odoo/quotations', [
            'client_id' => $client->id, 'action' => 'send', 'deliver' => 'email',
            'lines' => [['title' => 'باقة', 'amount' => 280, 'units' => 1]],
        ])->assertCreated();
        $request = ServiceRequest::query()->sole();
        $request->forceFill(['status' => RequestStatus::AwaitingPayment, 'amount_total' => 280, 'amount_remaining' => 280])->save();

        $updated = app(ConfirmRequestPayment::class);
        $updated->handle($request->fresh(), PaymentMethod::Receipt, 280);
        $this->assertSame('الفاتورة INV/2026/0090 مرحّلة ومدفوعة.', $updated->invoiceSummary());
        $this->assertNull($updated->invoiceError);
    }

    public function test_a_paper_previews_as_odoo_prints_it(): void
    {
        $this->odoo['portal'] = '/my/invoices/990?access_token=t1';
        Http::fake(['https://odoo.test/my/*' => Http::response('%PDF-1.4 invoice', 200, ['Content-Type' => 'application/pdf'])]);

        $response = $this->get('/api/admin/odoo/invoices/990/pdf')->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));

        $this->odoo['portal'] = false;
        $this->getJson('/api/admin/odoo/quotations/880/pdf')->assertStatus(502);
        $this->getJson('/api/admin/odoo/orders/880/pdf')->assertNotFound();
    }

    public function test_the_paper_activity_reads_odoo_chatter_and_logs_internal_notes(): void
    {
        $this->odoo['messages'] = [
            ['id' => 3, 'date' => '2026-10-14 09:00:00', 'body' => '<p>تم الإرسال<script>x()</script></p>', 'author_id' => [5, 'Sara'], 'message_type' => 'comment', 'subtype_id' => [1, 'Discussions'], 'tracking_value_ids' => [], 'is_internal' => false],
            ['id' => 2, 'date' => '2026-10-13 09:00:00', 'body' => '', 'author_id' => [5, 'Sara'], 'message_type' => 'notification', 'subtype_id' => [2, 'Quotation sent'], 'tracking_value_ids' => [41], 'is_internal' => false],
            ['id' => 1, 'date' => '2026-10-12 09:00:00', 'body' => '<p>اتصل العميل</p>', 'author_id' => [6, 'Omar'], 'message_type' => 'comment', 'subtype_id' => [3, 'Note'], 'tracking_value_ids' => [], 'is_internal' => true],
        ];

        $this->getJson('/api/admin/odoo/quotations/880/activity')->assertOk()
            ->assertJsonPath('data.0.body', '<p>تم الإرسال</p>')
            ->assertJsonPath('data.0.kind', 'message')
            ->assertJsonPath('data.1.kind', 'change')
            ->assertJsonPath('data.1.changes.0.field', 'Status')
            ->assertJsonPath('data.1.changes.0.old', 'Quotation')
            ->assertJsonPath('data.1.changes.0.new', 'Quotation Sent')
            ->assertJsonPath('data.2.kind', 'note');

        $this->postJson('/api/admin/odoo/invoices/990/notes', ['body' => "راجع المبلغ\n<b>مهم</b>"])->assertCreated();
        $call = collect($this->odoo['payloads'])->first(fn (array $row): bool => $row[0] === 'account.move' && $row[1] === 'message_post');
        $this->assertSame([990], $call[2][0]);
        $this->assertSame('mail.mt_note', $call[3]['subtype_xmlid']);
        $this->assertStringContainsString('راجع المبلغ<br/>&lt;b&gt;مهم&lt;/b&gt;', $call[3]['body']);
        $this->postJson('/api/admin/odoo/invoices/990/notes', ['body' => ''])->assertUnprocessable();
    }

    public function test_a_note_needs_its_own_permission(): void
    {
        $role = Role::query()->create(['name' => 'Viewer', 'abilities' => ['ops.clients.view', 'ops.clients.invoices_view']]);
        $staff = User::factory()->create(['is_admin' => false, 'role_id' => $role->id]);
        Sanctum::actingAs($staff);

        $this->getJson('/api/admin/odoo/invoices/990/activity')->assertOk();
        $this->postJson('/api/admin/odoo/invoices/990/notes', ['body' => 'x'])->assertForbidden();

        $role->forceFill(['abilities' => ['ops.clients.view', 'ops.clients.invoices_view', 'ops.clients.odoo_note']])->save();
        Sanctum::actingAs($staff->fresh());
        $this->postJson('/api/admin/odoo/invoices/990/notes', ['body' => 'x'])->assertCreated();
    }

    public function test_the_proforma_previews_and_reaches_the_client_without_making_an_invoice(): void
    {
        Mail::fake();
        $this->botClient();

        $response = $this->get('/api/admin/odoo/quotations/880/pdf?proforma=1')->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('proforma-S00880', (string) $response->headers->get('Content-Disposition'));
        $composer = collect($this->odoo['payloads'])->first(fn (array $row): bool => $row[0] === 'mail.compose.message' && $row[1] === 'create');
        $this->assertTrue($composer[3]['context']['proforma']);
        $this->assertSame(28, $composer[3]['context']['default_template_id']);
        $this->assertSame(1, $this->calls('ir.attachment', 'unlink'));
        $this->assertSame(0, $this->calls('mail.compose.message', 'action_send_mail'));

        $this->postJson('/api/admin/odoo/quotations/880/proforma', ['deliver' => 'email'])->assertOk()
            ->assertJsonPath('message', 'أُرسلت الفاتورة الشكلية S00880 للعميل.');
        Mail::assertSent(ClientDocumentMail::class, fn (ClientDocumentMail $mail): bool => $mail->hasTo('client@example.com'));
        $note = collect($this->odoo['payloads'])->first(fn (array $row): bool => $row[0] === 'sale.order' && $row[1] === 'message_post');
        $this->assertStringContainsString('أُرسلت الفاتورة الشكلية للعميل عبر البريد', $note[3]['body']);
        $this->assertSame(0, $this->calls('account.move', 'create'));
        $this->assertSame(0, $this->calls('sale.advance.payment.inv', 'create'));
        $this->assertSame(0, $this->calls('account.move', 'action_post'));
        $this->postJson('/api/admin/odoo/quotations/880/proforma', ['deliver' => 'fax'])->assertUnprocessable();
    }

    public function test_the_proforma_needs_its_own_permission(): void
    {
        $this->botClient();
        $role = Role::query()->create(['name' => 'Sales', 'abilities' => [
            'ops.clients.view',
            'ops.clients.odoo',
            'ops.clients.quotations_view',
        ]]);
        $staff = User::factory()->create(['is_admin' => false, 'role_id' => $role->id]);
        Sanctum::actingAs($staff);

        $this->get('/api/admin/odoo/quotations/880/pdf?proforma=1')->assertForbidden();
        $this->postJson('/api/admin/odoo/quotations/880/proforma', ['deliver' => 'email'])->assertForbidden();

        $role->forceFill(['abilities' => ['ops.clients.view', 'ops.clients.quotations_view', 'ops.clients.proforma']])->save();
        Sanctum::actingAs($staff->fresh());
        $this->get('/api/admin/odoo/quotations/880/pdf?proforma=1')->assertOk();
    }

    public function test_an_invoice_made_out_to_a_company_contact_finds_the_client_and_can_move_while_draft(): void
    {
        $client = $this->botClient();
        $this->odoo['move_partner'] = [45, 'Damastech, Rami'];
        $this->odoo['commercial'] = [44, 'Damastech'];
        $this->odoo['move'] = 'draft';

        $this->getJson('/api/admin/odoo/invoices/990')->assertOk()
            ->assertJsonPath('data.client_id', $client->id)
            ->assertJsonPath('data.client_name', 'Damastech');

        // A contact of the company still counts as the client when invoicing its quotation.
        $this->odoo['move'] = null;
        $this->odoo['order_partner'] = [45, 'Damastech, Rami'];
        $this->odoo['parents'] = ['commercial_partner_id' => [44, 'Damastech'], 'parent_id' => [44, 'Damastech']];
        $this->postJson('/api/admin/odoo/invoices', ['client_id' => $client->id, 'quotation_id' => 880, 'action' => 'draft'])->assertCreated();

        // A draft with another customer moves to the chosen client; its lines are left alone.
        $other = Client::factory()->create(['odoo_partner_id' => '70', 'email' => 'o@example.com']);
        $this->odoo['payloads'] = [];
        $this->putJson('/api/admin/odoo/invoices/990', ['client_id' => $other->id])->assertOk();
        $write = $this->payload('account.move', 'write');
        $this->assertSame(['partner_id' => 70], $write[1]);
        $this->assertSame(0, $this->calls('account.move', 'button_draft'));
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
        $this->odoo['payloads'][] = [$model, $method, $payload, $args[6] ?? []];
        $result = match (true) {
            $model === 'sale.order' && $method === 'create' => 880,
            $model === 'sale.order' && $method === 'action_quotation_sent' => $this->setOrder($this->odoo['order'] === 'draft' ? 'sent' : $this->odoo['order']),
            $model === 'sale.order' && $method === 'action_confirm' => $this->setOrder('sale'),
            $model === 'sale.order' && $method === 'action_cancel' => $this->setOrder('cancel'),
            $model === 'sale.order' && $method === 'write' => $this->writeOrderLines($payload),
            $model === 'sale.order' && $method === 'action_quotation_send' => ['context' => ['default_template_id' => 28, 'proforma' => true]],
            $model === 'mail.compose.message' && $method === 'create' => 4,
            $model === 'mail.compose.message' && $method === 'read' => [['id' => 4, 'attachment_ids' => [1155]]],
            $model === 'ir.attachment' && $method === 'read' => [['id' => 1155, 'mimetype' => 'application/pdf', 'datas' => base64_encode('%PDF-1.4 proforma')]],
            $model === 'ir.attachment' && $method === 'unlink' => true,
            $method === 'get_portal_url' => $this->odoo['portal'] ?? false,
            $method === 'message_post' => 501,
            $model === 'mail.message' && $method === 'search_read' => $this->odoo['messages'] ?? [],
            $model === 'mail.tracking.value' && $method === 'search_read' => [[
                'id' => 41, 'field_desc' => 'Status', 'old_value_char' => 'Quotation', 'new_value_char' => 'Quotation Sent',
            ]],
            $model === 'res.partner' && $method === 'search_read' => [['id' => 45, ...($this->odoo['parents'] ?? ['commercial_partner_id' => false, 'parent_id' => false])]],
            $model === 'sale.order' && $method === 'unlink' => $this->setOrder('gone'),
            // The numbers left after a delete, read to move the quotation sequence back.
            $model === 'sale.order' && $method === 'search_read' && ($payload[0][0] ?? null) === '|' => $this->odoo['names'] ?? [],
            $model === 'sale.order' && $method === 'search_read' && $this->odoo['order'] === 'gone' => [],
            $model === 'ir.sequence' && $method === 'search_read' => [['id' => 5, 'prefix' => 'QN', 'padding' => 5, 'number_next_actual' => $this->odoo['sequence_next'] ?? 881]],
            $model === 'ir.sequence' && $method === 'write' => true,
            $model === 'account.move' && $method === 'unlink' => $this->setMove('gone'),
            $model === 'account.move' && $method === 'search_read' && $this->odoo['move'] === 'gone' => [],
            $model === 'sale.order' && $method === 'search_read' => [[
                'id' => 880, 'name' => 'S00880', 'partner_id' => $this->odoo['order_partner'] ?? [44, 'Damastech'], 'amount_total' => 300,
                'currency_id' => [1, 'USD'], 'state' => $this->odoo['order'], 'client_order_ref' => 'DASH',
                'invoice_ids' => $this->odoo['invoice_ids'] ?? ($this->odoo['move'] === null ? [] : [990]),
                'note' => $this->odoo['note'] ?? false,
            ]],
            // Odoo's "Create invoice" wizard: a draft invoice linked to the order.
            $model === 'sale.advance.payment.inv' && $method === 'create' => [77],
            $model === 'sale.advance.payment.inv' && $method === 'create_invoices' => $this->wizardInvoice(),
            $model === 'account.move.line' && $method === 'search_read' => [['id' => 5, 'name' => 'Down payment of 50%', 'quantity' => 1, 'price_unit' => 150, 'discount' => 0, 'display_type' => 'product', 'product_id' => [9, 'Down payment'], 'tax_ids' => []]],
            $model === 'product.product' && $method === 'search_read' => [['id' => 12, 'name' => 'باقة شهرية', 'list_price' => 300, 'description_sale' => '<p>12 تصميم</p><p>4 ريلز</p>']],
            $model === 'account.tax' && $method === 'search_read' => [['id' => 7, 'name' => 'VAT 5%', 'amount' => 5, 'amount_type' => 'percent', 'price_include' => false]],
            $model === 'account.payment.term' && $method === 'search_read' => [['id' => 3, 'name' => '30 Days']],
            $model === 'sale.order.line' && $method === 'search_read' => $this->odoo['order_lines'] ?: [['name' => 'شعار', 'product_uom_qty' => 1, 'price_unit' => 200, 'discount' => 0, 'display_type' => false, 'product_id' => false]],
            $model === 'account.move' && $method === 'create' => $this->setMove('draft', 990),
            $model === 'account.move' && $method === 'action_post' => $this->moveState($payload, 'posted'),
            $model === 'account.payment.register' && $method === 'create' => $this->startPayment($args[6] ?? []),
            $model === 'account.payment.register' && $method === 'action_create_payments' => $this->pay(),
            $model === 'account.move' && $method === 'button_draft' => $this->setMove('draft'),
            $model === 'account.move' && $method === 'button_cancel' => $this->setMove('cancel'),
            $model === 'account.move' && $method === 'search_read' => [$this->moveRow($this->domainId($payload[0] ?? []) ?? 990)],
            default => [],
        };

        return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
    }

    /** @return array<string, mixed> */
    private function moveRow(int $id): array
    {
        $total = $id === 990 ? ($this->odoo['first_total'] ?? 280) : ($this->odoo['next_total'] ?? 150);
        $paid = $this->odoo['paid'][$id] ?? false;

        return [
            'id' => $id, 'name' => $id === 990 ? 'INV/2026/0090' : 'INV/2026/0'.$id, 'partner_id' => $this->odoo['move_partner'] ?? [44, 'Damastech'],
            'commercial_partner_id' => $this->odoo['commercial'] ?? false, 'amount_total' => $total,
            'amount_residual' => $paid ? 0 : $total, 'currency_id' => [1, 'USD'],
            'state' => $id === 990 ? ($this->odoo['move'] ?? 'draft') : ($this->odoo['states'][$id] ?? 'draft'),
            'payment_state' => $paid ? 'paid' : 'not_paid', 'invoice_origin' => 'S00880', 'ref' => 'DASH', 'invoice_date' => '2026-10-12',
            'move_type' => 'out_invoice',
        ];
    }

    /** @param  array<int, mixed>  $domain */
    private function domainId(array $domain): ?int
    {
        foreach ($domain as $term) {
            if (is_array($term) && ($term[0] ?? null) === 'id' && ($term[1] ?? null) === '=') {
                return (int) $term[2];
            }
        }

        return null;
    }

    /** Odoo's wizard adds the next invoice to the order, as a draft. */
    private function wizardInvoice(): array
    {
        $ids = $this->odoo['invoice_ids'] ?? ($this->odoo['move'] === null ? [] : [990]);
        $next = $ids === [] ? 990 : max($ids) + 1;
        $this->odoo['invoice_ids'] = [...$ids, $next];
        if ($next === 990) {
            $this->odoo['move'] = 'draft';
        } else {
            $this->odoo['states'][$next] = 'draft';
        }
        $this->odoo['last_move'] = $next;

        return ['type' => 'ir.actions.act_window'];
    }

    /** @param  array<int, mixed>  $payload */
    private function moveState(array $payload, string $state): bool
    {
        $first = $payload[0] ?? 990;
        $id = (int) (is_array($first) ? ($first[0] ?? 990) : $first);
        if ($id === 990) {
            $this->odoo['move'] = $state;
        } else {
            $this->odoo['states'][$id] = $state;
        }
        $this->odoo['last_move'] = $id;

        return true;
    }

    /** @param  array<string, mixed>  $kwargs */
    private function startPayment(array $kwargs): int
    {
        $this->odoo['paying'] = (int) ($kwargs['context']['active_ids'][0] ?? 990);

        return 701;
    }

    private function pay(): bool
    {
        $this->odoo['paid'][$this->odoo['paying'] ?? 990] = true;

        return true;
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
