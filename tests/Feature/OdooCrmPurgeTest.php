<?php

namespace Tests\Feature;

use App\Actions\PurgeOdooCrmCustomers;
use App\Actions\PushClientLeadToOdoo;
use App\Actions\SendQuotation;
use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\ServiceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesOdooDocuments;
use Tests\TestCase;

class OdooCrmPurgeTest extends TestCase
{
    use FakesOdooDocuments;
    use RefreshDatabase;

    public function test_purge_unlinks_crm_leads_and_clears_local_ids(): void
    {
        $client = Client::factory()->create([
            'odoo_lead_id' => '77',
            'odoo_partner_id' => '44',
            'odoo_stage_name' => 'تلغرام',
        ]);

        $this->fakeOdooDocuments();
        $leadReads = 0;
        $partnerReads = 0;
        $unlinked = [];

        Http::preventStrayRequests();
        Http::fake([
            'https://odoo.test/jsonrpc' => function (Request $request) use (&$leadReads, &$partnerReads, &$unlinked) {
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
                        ['id' => 11, 'name' => 'تلغرام'],
                    ]], 200);
                }

                if ($model === 'crm.lead' && $action === 'search_read') {
                    $leadReads++;

                    return Http::response([
                        'jsonrpc' => '2.0',
                        'result' => $leadReads === 1 ? [['id' => 77]] : [],
                    ], 200);
                }

                if ($model === 'res.partner' && $action === 'search_read') {
                    $partnerReads++;

                    return Http::response([
                        'jsonrpc' => '2.0',
                        'result' => $partnerReads === 1 ? [['id' => 44]] : [],
                    ], 200);
                }

                if ($action === 'unlink') {
                    $unlinked[$model] = $args[5][0] ?? [];

                    return Http::response(['jsonrpc' => '2.0', 'result' => true], 200);
                }

                return Http::response(['jsonrpc' => '2.0', 'result' => []], 200);
            },
        ]);

        $result = app(PurgeOdooCrmCustomers::class)->handle();

        $this->assertSame(1, $result['leads']);
        $this->assertSame(1, $result['partners']);
        $this->assertSame(1, $result['local_clients']);
        $this->assertSame([77], $unlinked['crm.lead'] ?? null);
        $this->assertSame([44], $unlinked['res.partner'] ?? null);

        $client->refresh();
        $this->assertNull($client->odoo_lead_id);
        $this->assertNull($client->odoo_partner_id);
        $this->assertNull($client->odoo_stage_name);
    }

    public function test_complete_profile_sends_telegram_stage_on_existing_pipeline(): void
    {
        Http::preventStrayRequests();
        $this->fakeOdooDocuments();
        Http::fake($this->odooDocumentsHttpFake());
        config(['services.gemini.e2e_stub' => true]);

        $this->withHeaders(['X-Webhook-Secret' => 'change-me-bot'])
            ->postJson('/api/bot/telegram/link', [
                'telegram_user_id' => 'tg-lead-stage',
                'name' => 'Sara',
                'phone' => '+963911111111',
                'company_name' => 'شركة الإبداع',
                'locale' => 'ar',
            ])
            ->assertOk()
            ->assertJsonPath('data.profile_complete', true)
            ->assertJsonPath('data.odoo_lead_id', '77')
            ->assertJsonPath('data.odoo_stage_name', 'تلغرام');

        Http::assertSent(function (Request $request): bool {
            $args = $request->data()['params']['args'] ?? [];
            if (($args[3] ?? null) !== 'crm.lead' || ($args[4] ?? null) !== 'create') {
                return false;
            }

            $vals = $args[5][0][0] ?? [];

            return ($vals['stage_id'] ?? null) === 11
                && ($vals['team_id'] ?? null) === 21
                && ($vals['user_id'] ?? null) === 2
                && ($vals['type'] ?? null) === 'opportunity'
                && ($vals['tag_ids'][0] ?? null) === [6, 0, [3]];
        });
    }

    public function test_send_quotation_links_sale_order_to_existing_opportunity(): void
    {
        Http::preventStrayRequests();
        $this->fakeOdooDocuments();
        Http::fake($this->odooDocumentsHttpFake());

        $client = Client::factory()->create([
            'name' => 'Sara',
            'company_name' => 'شركة الإبداع',
            'odoo_partner_id' => '44',
            'odoo_lead_id' => '77',
        ]);
        $request = ServiceRequest::factory()->for($client)->create([
            'status' => RequestStatus::Submitted,
        ]);

        app(SendQuotation::class)->handle($request, 500, 'عرض تجريبي');

        Http::assertSent(function (Request $httpRequest): bool {
            $args = $httpRequest->data()['params']['args'] ?? [];
            if (($args[3] ?? null) !== 'sale.order' || ($args[4] ?? null) !== 'create') {
                return false;
            }

            $vals = $args[5][0][0] ?? [];

            return ($vals['opportunity_id'] ?? null) === 77
                && ($vals['partner_id'] ?? null) === 44;
        });
    }

    public function test_writing_client_profile_never_resets_opportunity_stage_or_team(): void
    {
        Http::preventStrayRequests();
        $this->fakeOdooDocuments();
        Http::fake($this->odooDocumentsHttpFake());

        $client = Client::factory()->create([
            'name' => 'Sara',
            'company_name' => 'شركة الإبداع',
            'phone' => '+963911111111',
            'odoo_partner_id' => '44',
            'odoo_lead_id' => '77',
        ]);

        app(PushClientLeadToOdoo::class)->handle($client, writeExisting: true, classifyIndustry: false);

        Http::assertSent(function (Request $httpRequest): bool {
            $args = $httpRequest->data()['params']['args'] ?? [];
            if (($args[3] ?? null) !== 'crm.lead' || ($args[4] ?? null) !== 'write') {
                return false;
            }

            $vals = $args[5][1] ?? [];

            return ! array_key_exists('stage_id', $vals)
                && ! array_key_exists('team_id', $vals)
                && ! array_key_exists('user_id', $vals)
                && ($vals['tag_ids'][0] ?? null) === [4, 3];
        });
    }

    public function test_push_telegram_command_creates_missing_opportunity(): void
    {
        Http::preventStrayRequests();
        $this->fakeOdooDocuments();
        Http::fake($this->odooDocumentsHttpFake());

        $client = Client::factory()->create([
            'name' => 'AmmarHeroo',
            'phone' => '0950000700',
            'company_name' => 'Prodesign',
            'telegram_user_id' => '213309826',
            'odoo_lead_id' => null,
        ]);

        $this->artisan('odoo:push-telegram')
            ->expectsOutputToContain('pushed #'.$client->id)
            ->assertSuccessful();

        $this->assertSame('77', $client->fresh()->odoo_lead_id);
    }
}
