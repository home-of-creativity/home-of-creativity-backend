<?php

namespace Tests\Feature;

use App\Actions\CancelClientRequest;
use App\Enums\RequestStatus;
use App\Mail\ClientDocumentMail;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\ResolveServiceRequest;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A quotation sent from the dashboard is answered where the client reads it: «1» or
 * «موافقة» on WhatsApp, or the Odoo portal link in the email. Both reach the request.
 */
class OdooQuotationReplyTest extends TestCase
{
    use RefreshDatabase;

    private string $order = 'draft';

    private bool $odooEmpty = false;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'test-token',
            'services.whatsapp.transport' => 'web',
            'services.whatsapp.enabled' => false,
            'services.whatsapp.web_url' => 'http://wa-web.test',
            'services.whatsapp.web_secret' => 'web-secret',
            'services.odoo.enabled' => true,
            'services.odoo.url' => 'https://odoo.test',
            'services.odoo.db' => 'hoc',
            'services.odoo.username' => 'admin',
            'services.odoo.api_key' => 'secret-key',
            'services.odoo.use_json2' => false,
        ]);
        Mail::fake();
        Http::preventStrayRequests();
        Http::fake([
            'http://wa-web.test/*' => Http::response(['id' => 'wa-web-1']),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
            'https://odoo.test/jsonrpc' => fn (Request $request) => $this->odooAnswer($request),
        ]);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);
    }

    public function test_a_dashboard_client_approves_on_whatsapp_with_one_and_no_second_client_is_made(): void
    {
        $client = $this->dashboardClient();
        $this->sendQuotation($client, 'whatsapp');
        $request = ServiceRequest::query()->sole();

        // The chat now offers 1 approve / 2 reject for this quotation.
        Http::assertSent(fn (Request $sent): bool => str_contains($sent->url(), 'wa-web.test')
            && str_contains(json_encode($sent->data(), JSON_UNESCAPED_UNICODE), 'approve:'));

        $this->whatsApp('1');

        // The client is asked to pay now; the sales order waits for an admin's approval.
        $this->assertSame(RequestStatus::AwaitingAdminApproval, $request->fresh()->status);
        $this->assertTrue($request->fresh()->awaitsAdminApproval());
        $this->assertNotSame('sale', $this->order);
        $this->confirmClientApproval($request);
        $this->assertSame('sale', $this->order);
        $this->assertSame(1, Client::query()->count());
        $this->assertSame('wa:963944000123', $client->fresh()->telegram_user_id);
        Http::assertNotSent(fn (Request $sent): bool => str_contains(json_encode($sent->data(), JSON_UNESCAPED_UNICODE), 'كيف فيني ساعدك'));
    }

    public function test_two_rejects_with_a_reason_and_the_word_approval_works_too(): void
    {
        $client = $this->dashboardClient();
        $this->sendQuotation($client, 'whatsapp');

        $this->whatsApp('2');
        $this->assertSame(RequestStatus::QuotationSent, ServiceRequest::query()->sole()->status);
        // The gateway turns the picked number into the option id («1» = السعر غالي).
        $ref = ResolveServiceRequest::displayNumber(ServiceRequest::query()->sole());
        $this->whatsApp(null, 'rjprice:'.$ref);
        $this->assertSame(RequestStatus::QuotationRejected, ServiceRequest::query()->sole()->status);

        // A later quotation is approved by the word.
        $this->order = 'draft';
        $request = ServiceRequest::query()->sole();
        $this->postJson("/api/admin/requests/{$request->id}/quotation", ['lines' => [['title' => 'شعار', 'amount' => 150]]])->assertOk();
        $this->whatsApp('موافقة');
        $this->assertSame(RequestStatus::AwaitingAdminApproval, $request->fresh()->status);
    }

    public function test_the_email_carries_the_odoo_link_and_an_answer_on_odoo_reaches_the_request(): void
    {
        $client = $this->dashboardClient();
        $this->sendQuotation($client, 'email');
        $request = ServiceRequest::query()->sole();

        Mail::assertSent(ClientDocumentMail::class, fn (ClientDocumentMail $mail): bool => str_contains($mail->body, 'https://odoo.test/my/orders/880?access_token=abc'));

        // Nothing changes while the client has not answered.
        $this->artisan('odoo:sync-quotations')->assertSuccessful();
        $this->assertSame(RequestStatus::QuotationSent, $request->fresh()->status);

        // Accepted on the portal: the request moves as if the client pressed approve.
        $this->order = 'sale';
        $this->artisan('odoo:sync-quotations')->expectsOutputToContain('approved=1')->assertSuccessful();
        $this->assertSame(RequestStatus::AwaitingAdminApproval, $request->fresh()->status);
    }

    public function test_a_decline_on_the_odoo_portal_rejects_the_request(): void
    {
        $this->sendQuotation($this->dashboardClient(), 'email');
        $this->order = 'cancel';

        $this->artisan('odoo:sync-quotations')->expectsOutputToContain('rejected=1')->assertSuccessful();
        $this->assertSame(RequestStatus::QuotationRejected, ServiceRequest::query()->sole()->status);
    }

    public function test_a_quotation_deleted_in_odoo_deletes_its_unpaid_request_and_cancels_a_paid_one(): void
    {
        $client = $this->dashboardClient();
        $unpaid = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::AwaitingPayment,
            'odoo_quotation_id' => '880',
            'amount_paid' => 0,
        ]);
        Invoice::query()->create([
            'request_id' => $unpaid->id,
            'invoice_number' => 'INV-REQ-UNPAID',
            'amount' => 200,
            'kind' => 'full',
            'status' => 'issued',
            'issued_at' => now(),
        ]);
        $paid = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::InProgress,
            'odoo_quotation_id' => '880',
            'amount_paid' => 150,
        ]);
        $this->order = 'gone';

        $this->artisan('odoo:sync-quotations')->expectsOutputToContain('deleted=1 cancelled=1')->assertSuccessful();

        $this->assertNull($unpaid->fresh());
        $this->assertSame(0, Invoice::query()->where('invoice_number', 'INV-REQ-UNPAID')->count());
        $this->assertSame(RequestStatus::Cancelled, $paid->fresh()->status);
        $this->assertNull($paid->fresh()->odoo_quotation_id);
    }

    public function test_a_draft_the_dashboard_removed_from_odoo_keeps_its_request(): void
    {
        $request = ServiceRequest::factory()->for($this->dashboardClient())->create([
            'status' => RequestStatus::QuotationSent,
            'odoo_quotation_id' => '880',
        ]);
        $this->order = 'draft';

        app(CancelClientRequest::class)->closeOdooPapers($request, 'admin');
        $this->assertSame('gone', $this->order);
        $this->assertNull($request->fresh()->odoo_quotation_id);

        $this->artisan('odoo:sync-quotations')->expectsOutputToContain('deleted=0')->assertSuccessful();
        $this->assertNotNull($request->fresh());
    }

    public function test_nothing_is_deleted_when_odoo_has_no_orders_at_all(): void
    {
        $request = ServiceRequest::factory()->for($this->dashboardClient())->create([
            'status' => RequestStatus::AwaitingPayment,
            'odoo_quotation_id' => '880',
        ]);
        $this->order = 'gone';
        $this->odooEmpty = true;

        $this->artisan('odoo:sync-quotations')->expectsOutputToContain('deleted=0')->assertSuccessful();
        $this->assertNotNull($request->fresh());
    }

    private function dashboardClient(): Client
    {
        return Client::factory()->create([
            'name' => 'سامر',
            'company_name' => 'Damastech',
            'email' => 'client@example.com',
            'phone' => '0944 000 123',
            'odoo_partner_id' => '44',
            'telegram_user_id' => null,
        ]);
    }

    private function sendQuotation(Client $client, string $deliver): void
    {
        $this->postJson('/api/admin/odoo/quotations', [
            'client_id' => $client->id, 'action' => 'send', 'deliver' => $deliver,
            'lines' => [['title' => 'شعار', 'amount' => 200, 'units' => 1]],
        ])->assertCreated();
    }

    private function whatsApp(?string $text, ?string $button = null): void
    {
        $this->withHeaders(['X-Webhook-Secret' => 'web-secret'])
            ->postJson('/api/bot/whatsapp/web', array_filter(['phone' => '963944000123', 'message_id' => 'm-'.uniqid(), 'text' => $text, 'button_id' => $button]))
            ->assertOk();
    }

    private function odooAnswer(Request $request): PromiseInterface
    {
        $params = $request->data()['params'] ?? [];
        if (($params['service'] ?? '') === 'common') {
            return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => 2]);
        }
        $args = $params['args'] ?? [];
        [$model, $method] = [$args[3] ?? '', $args[4] ?? ''];
        $result = match (true) {
            $model === 'sale.order' && $method === 'create' => $this->setOrder('draft', 880),
            $model === 'sale.order' && $method === 'action_quotation_sent' => $this->setOrder($this->order === 'draft' ? 'sent' : $this->order),
            $model === 'sale.order' && $method === 'action_confirm' => $this->setOrder('sale'),
            $model === 'sale.order' && $method === 'action_cancel' => $this->setOrder('cancel'),
            $model === 'sale.order' && $method === 'unlink' => $this->setOrder('gone'),
            $model === 'sale.order' && $method === 'get_portal_url' => '/my/orders/880?access_token=abc',
            // Other orders stay in Odoo unless it is empty, so «any order at all» is still true.
            $model === 'sale.order' && $method === 'search_read' && $this->order === 'gone' => ($args[5][0] ?? []) === [] && ! $this->odooEmpty ? [['id' => 1]] : [],
            $model === 'sale.order' && $method === 'search_read' => [[
                'id' => 880, 'name' => 'S00880', 'partner_id' => [44, 'Damastech'], 'amount_total' => 200,
                'currency_id' => [1, 'USD'], 'state' => $this->order, 'client_order_ref' => 'DASH',
            ]],
            default => [],
        };

        return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
    }

    private function setOrder(string $state, mixed $result = true): mixed
    {
        $this->order = $state;

        return $result;
    }
}
