<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientReportVersion;
use App\Models\User;
use App\Services\GoogleDriveClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportHistoryCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->admin = User::factory()->admin()->create(['name' => 'رنا']);
        Sanctum::actingAs($this->admin);
    }

    private function docx(string $bytes): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('report.docx', $bytes);
    }

    private function createReport(Client $client, string $bytes = 'PK one'): int
    {
        return (int) $this->post("/api/admin/clients/{$client->id}/reports", [
            'title' => 'تقرير أكتوبر',
            'body' => 'نص',
            'document' => $this->docx($bytes),
        ])->assertCreated()->json('data.id');
    }

    private function save(int $id, string $bytes): void
    {
        $this->post("/api/admin/reports/{$id}", [
            'title' => 'تقرير أكتوبر',
            'body' => 'نص',
            'document' => $this->docx($bytes),
        ])->assertOk();
    }

    public function test_autosaves_fold_into_one_session_and_a_pause_starts_another(): void
    {
        $client = Client::factory()->create();
        $id = $this->createReport($client, 'PK one');

        $this->travel(5)->seconds();
        $this->save($id, 'PK two');
        $this->travel(3)->minutes();
        $this->save($id, 'PK three');

        $rows = $this->getJson("/api/admin/report-history?report_id={$id}")->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('created', $rows[0]['event']);
        $this->assertSame(3, $rows[0]['saves']);
        $this->assertSame('رنا', $rows[0]['user']['name']);
        $this->assertSame('PK three', Storage::disk('local')->get("reports/{$id}/versions/{$rows[0]['id']}.docx"));

        $this->travel(11)->minutes();
        $this->save($id, 'PK four');

        $rows = $this->getJson("/api/admin/report-history?report_id={$id}")->json('data');
        $this->assertSame(['edited', 'created'], array_column($rows, 'event'));
        $this->get("/api/admin/report-versions/{$rows[1]['id']}/document")->assertOk()->assertDownload();
    }

    public function test_restoring_an_older_version_makes_it_the_current_file(): void
    {
        $client = Client::factory()->create();
        $id = $this->createReport($client, 'PK first draft');
        $first = ClientReportVersion::query()->where('client_report_id', $id)->firstOrFail();

        $this->travel(20)->minutes();
        $this->save($id, 'PK rewritten');
        $this->assertSame('PK rewritten', Storage::disk('local')->get("reports/{$id}/report.docx"));

        $this->putJson("/api/admin/report-versions/{$first->id}/restore")->assertOk()->assertJsonPath('data.id', $id);

        $this->assertSame('PK first draft', Storage::disk('local')->get("reports/{$id}/report.docx"));
        $latest = $this->getJson("/api/admin/report-history?report_id={$id}")->json('data.0');
        $this->assertSame('restored', $latest['event']);
        $this->assertSame($first->id, $latest['detail']['from_version']);
    }

    public function test_a_deleted_report_stays_on_the_client_history(): void
    {
        $client = Client::factory()->create();
        $id = $this->createReport($client);

        $this->deleteJson("/api/admin/reports/{$id}")->assertOk();

        $rows = $this->getJson("/api/admin/report-history?client_id={$client->id}")->json('data');
        $this->assertSame(['deleted', 'created'], array_column($rows, 'event'));
        $this->assertNull($rows[0]['report_id']);
        $this->assertFalse($rows[1]['has_document']);
    }

    public function test_a_monthly_deadline_repeats_and_dates_can_be_moved_skipped_and_done(): void
    {
        Carbon::setTestNow('2026-10-12 09:00:00');
        $client = Client::factory()->create(['name' => 'متجر الورد']);

        $deadline = $this->postJson('/api/admin/report-deadlines', [
            'client_id' => $client->id,
            'title' => 'التقرير الشهري',
            'starts_on' => '2026-08-31',
            'due_time' => '14:00',
            'repeat' => 'monthly',
        ])->assertCreated()->json('data.id');

        $rows = $this->getJson('/api/admin/report-calendar?from=2026-09-01&to=2026-12-01')->assertOk()->json('data.deadlines');
        // The 31st falls back to the last day of shorter months.
        $this->assertSame(['2026-09-30', '2026-10-31', '2026-11-30'], array_column($rows, 'date'));
        $this->assertSame(['overdue', 'upcoming', 'upcoming'], array_column($rows, 'status'));
        $this->assertSame('متجر الورد', $rows[0]['client']['name']);

        $this->putJson("/api/admin/report-deadlines/{$deadline}/occurrences/2026-09-30", ['action' => 'skip'])->assertOk();
        $this->putJson("/api/admin/report-deadlines/{$deadline}/occurrences/2026-10-31", ['action' => 'move', 'date' => '2026-10-29', 'time' => '10:30'])->assertOk();
        $this->putJson("/api/admin/report-deadlines/{$deadline}/occurrences/2026-10-15", ['action' => 'skip'])->assertNotFound();

        $rows = $this->getJson("/api/admin/report-calendar?from=2026-09-01&to=2026-12-01&client_id={$client->id}")->json('data.deadlines');
        $this->assertSame('skipped', $rows[0]['status']);
        $this->assertSame(['2026-10-29', '10:30', true], [$rows[1]['date'], $rows[1]['time'], $rows[1]['moved']]);

        $this->putJson("/api/admin/report-deadlines/{$deadline}/occurrences/2026-10-31", ['action' => 'reset'])->assertOk();
        $this->assertSame('2026-10-31', $this->getJson('/api/admin/report-calendar?from=2026-10-01&to=2026-11-01')->json('data.deadlines.0.date'));
    }

    public function test_publishing_marks_the_open_deadline_done_once(): void
    {
        Carbon::setTestNow('2026-10-12 09:00:00');
        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('lastError')->andReturn(null);
            $mock->shouldReceive('uploadFile')->andReturn(['id' => 'doc-1', 'url' => 'https://drive.google.com/file/d/doc-1/view']);
            $mock->shouldReceive('replaceFile')->andReturn(['id' => 'doc-1', 'url' => 'https://drive.google.com/file/d/doc-1/view']);
        });
        $client = Client::factory()->create(['google_drive_folder_id' => 'folder-1']);
        $deadline = $this->postJson('/api/admin/report-deadlines', [
            'client_id' => $client->id,
            'title' => 'تقرير أسبوعي',
            'starts_on' => '2026-10-08',
            'repeat' => 'weekly',
        ])->assertCreated()->json('data.id');

        $id = $this->createReport($client);
        $this->post("/api/admin/reports/{$id}", ['title' => 'تقرير أكتوبر', 'publish' => '1'])->assertOk()->assertJsonPath('drive_error', null);
        $this->post("/api/admin/reports/{$id}", ['title' => 'تقرير أكتوبر', 'publish' => '1'])->assertOk();

        $rows = $this->getJson('/api/admin/report-calendar?from=2026-10-05&to=2026-10-19')->json('data.deadlines');
        $this->assertSame(['2026-10-08', '2026-10-15'], array_column($rows, 'date'));
        $this->assertSame(['done', 'upcoming'], array_column($rows, 'status'));
        $this->assertSame($id, $rows[0]['report']['id']);

        $events = array_column($this->getJson("/api/admin/report-history?report_id={$id}")->json('data'), 'event');
        $this->assertSame(['published', 'published', 'created'], $events);

        $activity = $this->getJson('/api/admin/report-calendar?from=2026-10-05&to=2026-10-19')->json('data.activity');
        $this->assertCount(3, $activity);
        $this->assertNotNull($deadline);
    }
}
