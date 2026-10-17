<?php

namespace Tests\Feature;

use App\Actions\ConvertDocxToPdf;
use App\Enums\StaffAbility;
use App\Mail\ClientDocumentMail;
use App\Models\Client;
use App\Models\ClientReport;
use App\Models\ReportDeadline;
use App\Models\ReportDeadlineOccurrence;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The report tab sends the PDF to the client by email and WhatsApp. */
class ClientReportSendTest extends TestCase
{
    use RefreshDatabase;

    private ClientReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00', 'Asia/Damascus'));
        Storage::fake('local');
        Mail::fake();
        config([
            'services.whatsapp.transport' => 'web',
            'services.whatsapp.web_url' => 'http://wa-web.test',
            'services.whatsapp.web_secret' => 'web-secret',
        ]);
        Http::fake(['http://wa-web.test/*' => Http::response(['id' => 'wa-1'], 200)]);
        $this->mock(ConvertDocxToPdf::class)->shouldReceive('handle')->andReturn('%PDF-1.4 report');

        $client = Client::factory()->create(['name' => 'نور', 'email' => 'old@shop.com', 'phone' => '+963944000111']);
        Storage::disk('local')->put('reports/1/report.docx', 'docx-bytes');
        $this->report = ClientReport::query()->create([
            'client_id' => $client->id,
            'title' => 'تقرير أكتوبر',
            'body' => '',
            'document_path' => 'reports/1/report.docx',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_pdf_goes_by_email_and_whatsapp_and_closes_the_report_date(): void
    {
        $deadline = ReportDeadline::query()->create([
            'client_id' => $this->report->client_id,
            'title' => 'تقرير شهري',
            'starts_on' => '2026-10-15',
            'repeat' => 'none',
        ]);
        $sender = $this->staff([StaffAbility::OpsReports->value.'.view', StaffAbility::OpsReports->value.'.send']);
        Sanctum::actingAs($sender);

        $this->postJson("/api/admin/reports/{$this->report->id}/send", [
            'email' => 'new@shop.com',
            'phone' => '+963944000111',
            'message' => 'هذا تقرير الشهر.',
            'save_contact' => true,
        ])
            ->assertOk()
            ->assertJsonPath('message', 'أُرسل التقرير للعميل.')
            ->assertJsonCount(2, 'data');

        Mail::assertSent(ClientDocumentMail::class, fn (ClientDocumentMail $mail): bool => $mail->hasTo('new@shop.com')
            && str_contains($mail->body, 'هذا تقرير الشهر.')
            && str_contains($mail->body, 'تقرير أكتوبر')
            && $mail->filename === 'تقرير أكتوبر.pdf');
        Http::assertSent(fn (Request $sent): bool => $sent->url() === 'http://wa-web.test/send'
            && $sent['to'] === '963944000111'
            && $sent['kind'] === 'document'
            && $sent['filename'] === 'تقرير أكتوبر.pdf'
            && base64_decode((string) $sent['data_base64']) === '%PDF-1.4 report'
            && str_contains((string) $sent['caption'], 'هذا تقرير الشهر.'));

        $this->assertSame('new@shop.com', $this->report->client->fresh()->email);
        $occurrence = ReportDeadlineOccurrence::query()->where('report_deadline_id', $deadline->id)->first();
        $this->assertNotNull($occurrence?->done_at);
        $this->assertSame($this->report->id, $occurrence->client_report_id);

        $log = $this->getJson("/api/admin/reports/{$this->report->id}/sends")->assertOk()->json('data');
        $this->assertSame(['whatsapp', 'email'], array_column($log, 'channel'));
        $this->assertSame($sender->name, $log[0]['user']['name']);
        $this->assertTrue($log[1]['delivered']);
    }

    public function test_without_saving_the_contact_the_client_keeps_their_email(): void
    {
        Sanctum::actingAs($this->staff([StaffAbility::OpsReports->value]));

        $this->postJson("/api/admin/reports/{$this->report->id}/send", ['email' => 'someone@else.com'])->assertOk();

        $this->assertSame('old@shop.com', $this->report->client->fresh()->email);
        Mail::assertSent(ClientDocumentMail::class, fn (ClientDocumentMail $mail): bool => $mail->hasTo('someone@else.com'));
    }

    public function test_a_send_needs_a_recipient_a_saved_report_and_the_permission(): void
    {
        Sanctum::actingAs($this->staff([StaffAbility::OpsReports->value.'.view', StaffAbility::OpsReports->value.'.update']));
        $this->postJson("/api/admin/reports/{$this->report->id}/send", ['email' => 'a@b.com'])->assertForbidden();

        Sanctum::actingAs($this->staff([StaffAbility::OpsReports->value.'.send']));
        $this->postJson("/api/admin/reports/{$this->report->id}/send", [])->assertUnprocessable();
        $this->postJson("/api/admin/reports/{$this->report->id}/send", ['phone' => '12'])->assertUnprocessable();

        $this->report->forceFill(['document_path' => null])->save();
        $this->postJson("/api/admin/reports/{$this->report->id}/send", ['email' => 'a@b.com'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.report.0', 'احفظ التقرير أولاً.');
        Mail::assertNothingSent();
    }

    public function test_a_failed_whatsapp_send_does_not_mark_the_calendar_delivered(): void
    {
        $deadline = ReportDeadline::query()->create([
            'client_id' => $this->report->client_id,
            'title' => 'تقرير شهري',
            'starts_on' => '2026-10-15',
            'repeat' => 'none',
        ]);
        config(['services.whatsapp.web_url' => '']);
        Sanctum::actingAs($this->staff([StaffAbility::OpsReports->value.'.send']));

        $this->postJson("/api/admin/reports/{$this->report->id}/send", [
            'email' => 'new@shop.com',
            'phone' => '+963944000111',
        ])->assertOk();

        $this->assertNull(ReportDeadlineOccurrence::query()->where('report_deadline_id', $deadline->id)->first()?->done_at);
    }

    public function test_a_failed_delivery_is_logged_and_reported(): void
    {
        config(['services.whatsapp.web_url' => '']);
        Sanctum::actingAs($this->staff([StaffAbility::OpsReports->value]));

        $this->postJson("/api/admin/reports/{$this->report->id}/send", ['phone' => '+963944000111'])
            ->assertUnprocessable()
            ->assertJsonPath('data.0.delivered', false);

        $this->assertSame(0, ReportDeadlineOccurrence::query()->count());
        $this->assertSame(1, $this->report->sends()->count());
    }

    /** @param  list<string>  $abilities */
    private function staff(array $abilities): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->create(['name' => 'دور '.uniqid(), 'abilities' => $abilities])->id,
        ]);
    }
}
