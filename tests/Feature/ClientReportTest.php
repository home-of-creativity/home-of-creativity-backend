<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Services\GoogleDriveClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function docx(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('report.docx', 'PK docx bytes');
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('report.pdf', '%PDF-1.7 bytes');
    }

    public function test_publishing_requires_the_client_drive_folder(): void
    {
        $client = Client::factory()->create(['company_name' => 'دار الإبداع']);

        $this->post("/api/admin/clients/{$client->id}/reports", [
            'title' => 'تقرير',
            'document' => $this->docx(),
            'publish' => '1',
        ])->assertStatus(422);

        $this->assertSame(0, $client->reports()->count());
    }

    public function test_a_draft_saves_the_word_file_without_drive(): void
    {
        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldNotReceive('uploadFile');
        });
        $client = Client::factory()->create();

        $response = $this->post("/api/admin/clients/{$client->id}/reports", [
            'title' => 'مسودة',
            'body' => 'نص التقرير',
            'document' => $this->docx(),
            'pdf' => $this->pdf(),
        ])->assertCreated()
            ->assertJsonPath('data.has_document', true)
            ->assertJsonPath('data.has_pdf', true)
            ->assertJsonPath('data.published_at', null)
            ->assertJsonPath('drive_error', null);

        $id = $response->json('data.id');
        Storage::disk('local')->assertExists("reports/{$id}/report.docx");
        Storage::disk('local')->assertExists("reports/{$id}/report.pdf");

        $this->get("/api/admin/reports/{$id}/document")->assertOk()->assertDownload();
        $this->get("/api/admin/reports/{$id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_a_new_report_needs_its_word_file(): void
    {
        $client = Client::factory()->create();

        $this->postJson("/api/admin/clients/{$client->id}/reports", ['title' => 'تقرير'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('document');
    }

    public function test_publishing_uploads_the_word_file_pdf_and_attachment_then_replaces_them(): void
    {
        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('ensureFolderPath')->andReturn('sub-folder');
            $mock->shouldReceive('lastError')->andReturn(null);
            $mock->shouldReceive('uploadFile')->times(3)->andReturnUsing(fn (string $folder, string $name): array => [
                'id' => 'id-'.$name,
                'url' => 'https://drive.google.com/file/d/'.$name.'/view',
            ]);
            $mock->shouldReceive('replaceFile')->twice()->andReturnUsing(fn (string $folder, string $id): array => [
                'id' => $id,
                'url' => 'https://drive.google.com/file/d/'.$id.'/view',
            ]);
        });
        $client = Client::factory()->create(['google_drive_folder_id' => 'parent-1']);

        $id = $this->post("/api/admin/clients/{$client->id}/reports", [
            'title' => 'تقرير الربع',
            'document' => $this->docx(),
            'pdf' => $this->pdf(),
            'attachments' => [UploadedFile::fake()->create('notes.pdf', 12, 'application/pdf')],
            'publish' => '1',
        ])->assertCreated()
            ->assertJsonPath('data.drive_document_url', 'https://drive.google.com/file/d/تقرير الربع.docx/view')
            ->assertJsonPath('data.drive_url', 'https://drive.google.com/file/d/تقرير الربع.pdf/view')
            ->assertJsonPath('data.attachments.0.name', 'notes.pdf')
            ->assertJsonPath('drive_error', null)
            ->json('data.id');

        $this->assertNotNull($client->reports()->first()?->published_at);

        // Republishing replaces the same two Drive files and does not upload the attachment again.
        $this->post("/api/admin/reports/{$id}", [
            'title' => 'تقرير الربع',
            'document' => $this->docx(),
            'pdf' => $this->pdf(),
            'publish' => '1',
        ])->assertOk()->assertJsonPath('drive_error', null);
    }

    public function test_a_drive_failure_keeps_the_saved_report_and_explains_why(): void
    {
        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('ensureFolderPath')->andReturn(null);
            $mock->shouldReceive('lastError')->andReturn('Drive is down.');
        });
        $client = Client::factory()->create(['google_drive_folder_id' => 'parent-1']);

        $id = $this->post("/api/admin/clients/{$client->id}/reports", [
            'title' => 'تقرير',
            'document' => $this->docx(),
            'publish' => '1',
        ])->assertCreated()
            ->assertJsonPath('drive_error', 'Drive is down.')
            ->json('data.id');

        $this->post("/api/admin/reports/{$id}", [
            'title' => 'تقرير معدل',
            'publish' => '1',
        ])->assertOk()->assertJsonPath('drive_error', 'Drive is down.');

        $this->assertSame(1, $client->reports()->count());
        $this->assertSame('تقرير معدل', $client->reports()->first()?->title);
    }

    public function test_a_report_saved_before_word_files_cannot_publish_until_saved_again(): void
    {
        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldNotReceive('uploadFile');
        });
        $client = Client::factory()->create(['google_drive_folder_id' => 'parent-1']);
        $report = $client->reports()->create(['title' => 'قديم', 'body' => '<p>متن</p>']);

        $this->post("/api/admin/reports/{$report->id}", ['title' => 'قديم', 'publish' => '1'])
            ->assertOk()
            ->assertJsonPath('data.has_document', false)
            ->assertJsonPath('drive_error', 'Open and save this report in the editor before publishing.');

        $this->get("/api/admin/reports/{$report->id}/document")->assertNotFound();
    }

    public function test_deleting_a_report_removes_its_files(): void
    {
        $client = Client::factory()->create();
        $id = $this->post("/api/admin/clients/{$client->id}/reports", [
            'title' => 'تقرير',
            'document' => $this->docx(),
        ])->assertCreated()->json('data.id');

        $this->deleteJson("/api/admin/reports/{$id}")->assertOk();

        Storage::disk('local')->assertMissing("reports/{$id}/report.docx");
        $this->assertSame(0, $client->reports()->count());
    }
}
