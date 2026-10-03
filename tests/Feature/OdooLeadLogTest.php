<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Jobs\ClassifyWithGeminiJob;
use App\Models\Client;
use App\Models\OdooLeadNote;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\OdooLeadLog;
use App\Services\RequestStatusTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesOdooDocuments;
use Tests\TestCase;

class OdooLeadLogTest extends TestCase
{
    use FakesOdooDocuments;
    use RefreshDatabase;

    private bool $odooUp = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeOdooDocuments();
        $documents = $this->odooDocumentsHttpFake();
        Http::preventStrayRequests();
        Http::fake([
            'https://odoo.test/jsonrpc' => function (Request $request) use ($documents) {
                if (! $this->odooUp) {
                    return (Http::failedConnection())($request);
                }

                return $documents['https://odoo.test/jsonrpc']($request);
            },
            'https://odoo.test/*' => $documents['https://odoo.test/*'],
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);
    }

    public function test_odoo_down_does_not_block_payment_and_the_note_posts_later(): void
    {
        Bus::fake([ClassifyWithGeminiJob::class]);
        $this->odooUp = false;
        $request = $this->requestForLead(RequestStatus::AwaitingPayment);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->postJson("/api/admin/requests/{$request->number}/confirm-payment", [
            'payment_method' => 'cash',
            'amount' => 150,
        ])->assertOk()
            ->assertJsonPath('data.status', 'payment_confirmed');

        $pending = OdooLeadNote::query()->whereNull('posted_at')->get();
        $this->assertTrue($pending->contains(fn (OdooLeadNote $note): bool => str_contains($note->body, 'المقبوض الآن: 150.00 USD')));
        $this->assertTrue($pending->contains(fn (OdooLeadNote $note): bool => str_contains($note->body, 'الحالة: تم تأكيد الدفع')));
        $this->assertSame(0, $this->postedNotes());

        $this->odooUp = true;
        $this->travel(2)->hours();
        $this->artisan('odoo:reconcile')->assertSuccessful();

        $this->assertSame(0, OdooLeadNote::query()->whereNull('posted_at')->count());
        $this->assertGreaterThanOrEqual(2, $this->postedNotes());
        Http::assertSent(function (Request $sent): bool {
            $args = $sent->data()['params']['args'] ?? [];

            return ($args[3] ?? null) === 'crm.lead'
                && ($args[4] ?? null) === 'message_post'
                && str_contains((string) data_get($args, '6.body'), 'سُجّل في');
        });
    }

    public function test_notes_wait_for_the_lead_and_never_duplicate(): void
    {
        $client = Client::factory()->create(['telegram_user_id' => 'tg-no-lead-yet', 'odoo_lead_id' => null]);
        $request = ServiceRequest::factory()->for($client)->create(['status' => RequestStatus::Submitted]);
        $log = app(OdooLeadLog::class);

        $log->requestCreated($request);
        $log->requestCreated($request);
        $this->assertSame(1, OdooLeadNote::query()->count());

        $this->assertSame(0, $log->flush());
        $this->assertNull(OdooLeadNote::query()->first()->posted_at);

        $client->forceFill(['odoo_lead_id' => '77'])->save();
        $this->assertSame(1, $log->flush());
        $this->assertNotNull(OdooLeadNote::query()->first()->posted_at);
    }

    public function test_status_change_records_the_reason_on_the_lead(): void
    {
        $request = $this->requestForLead(RequestStatus::QuotationSent);

        app(RequestStatusTransitionService::class)->transition(
            $request,
            RequestStatus::QuotationRejected,
            'client',
            "الباقة: Startup\nالسعر غالي",
        );

        $note = OdooLeadNote::query()->latest('id')->firstOrFail();
        $this->assertStringContainsString('الحالة: عرض مرفوض', $note->body);
        $this->assertStringContainsString('بواسطة: الزبون', $note->body);
        $this->assertStringContainsString('السعر غالي', $note->body);
    }

    private function requestForLead(RequestStatus $status): ServiceRequest
    {
        $client = Client::factory()->create([
            'telegram_user_id' => '555000111',
            'company_name' => 'شركة السجل',
            'company_activity' => 'مطاعم',
            'odoo_partner_id' => '44',
            'odoo_lead_id' => '77',
        ]);

        return ServiceRequest::factory()->for($client)->create([
            'status' => $status,
            'quotation_amount' => 300,
            'amount_total' => 300,
        ]);
    }

    private function postedNotes(): int
    {
        return collect(Http::recorded())->filter(function (array $pair): bool {
            $args = $pair[0]->data()['params']['args'] ?? [];

            return ($args[4] ?? null) === 'message_post' && ($args[3] ?? null) === 'crm.lead';
        })->count();
    }
}
