<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Services\GoogleDriveClient;
use App\Support\ShrinkDocxImages;
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

    public function test_saving_a_new_word_file_with_the_same_text_still_counts_as_an_edit(): void
    {
        $client = Client::factory()->create();
        $id = $this->post("/api/admin/clients/{$client->id}/reports", [
            'title' => 'تقرير',
            'body' => 'نص',
            'document' => $this->docx(),
        ])->assertCreated()->json('data.id');
        $first = $this->getJson("/api/admin/reports/{$id}")->json('data.updated_at');

        $this->travel(5)->seconds();
        $this->post("/api/admin/reports/{$id}", [
            'title' => 'تقرير',
            'body' => 'نص',
            'document' => UploadedFile::fake()->createWithContent('report.docx', 'PK bold heading now'),
        ])->assertOk();

        $this->assertNotSame($first, $this->getJson("/api/admin/reports/{$id}")->json('data.updated_at'));
        $this->assertSame('PK bold heading now', Storage::disk('local')->get("reports/{$id}/report.docx"));
    }

    public function test_a_new_report_needs_its_word_file(): void
    {
        $client = Client::factory()->create();

        $this->postJson("/api/admin/clients/{$client->id}/reports", ['title' => 'تقرير'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('document');
    }

    public function test_publishing_uploads_the_word_file_then_replaces_it(): void
    {
        $folders = [];
        $replaced = [];
        $this->mock(GoogleDriveClient::class, function ($mock) use (&$folders, &$replaced): void {
            $mock->shouldReceive('lastError')->andReturn(null);
            $mock->shouldReceive('uploadFile')->times(2)->andReturnUsing(function (string $folder, string $name, string $contents, string $mime) use (&$folders): array {
                $folders[] = $folder;

                return [
                    'id' => 'id-'.$name,
                    'url' => 'https://drive.google.com/file/d/'.$name.'/view',
                ];
            });
            $mock->shouldReceive('replaceFile')->once()->andReturnUsing(function (string $folder, string $id, string $name, string $contents, string $mime) use (&$replaced): array {
                $replaced[] = [$folder, $id, $name];

                return [
                    'id' => $id,
                    'url' => 'https://drive.google.com/file/d/'.$id.'/view',
                ];
            });
        });
        $client = Client::factory()->create(['google_drive_folder_id' => 'parent-1']);

        $id = $this->post("/api/admin/clients/{$client->id}/reports", [
            'title' => 'تقرير الربع',
            'document' => $this->docx(),
            'attachments' => [UploadedFile::fake()->create('notes.pdf', 12, 'application/pdf')],
            'publish' => '1',
        ])->assertCreated()
            ->assertJsonPath('data.drive_document_url', 'https://drive.google.com/file/d/تقرير الربع.docx/view')
            ->assertJsonPath('data.drive_url', null)
            ->assertJsonPath('data.attachments.0.name', 'notes.pdf')
            ->assertJsonPath('drive_error', null)
            ->json('data.id');

        $this->assertSame(['parent-1', 'parent-1'], $folders);
        $this->assertNotNull($client->reports()->first()?->published_at);

        // Republishing replaces the same Word file and does not upload the attachment again.
        $this->post("/api/admin/reports/{$id}", [
            'title' => 'تقرير الربع',
            'document' => $this->docx(),
            'publish' => '1',
        ])->assertOk()
            ->assertJsonPath('data.drive_document_url', 'https://drive.google.com/file/d/id-تقرير الربع.docx/view')
            ->assertJsonPath('data.drive_url', null)
            ->assertJsonPath('drive_error', null);

        $this->assertSame([
            ['parent-1', 'id-تقرير الربع.docx', 'تقرير الربع.docx'],
        ], $replaced);
    }

    public function test_a_drive_failure_keeps_the_saved_report_and_explains_why(): void
    {
        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('uploadFile')->twice()->andReturn(null);
            $mock->shouldReceive('lastError')->andReturn('Drive is down.');
        });
        $client = Client::factory()->create(['google_drive_folder_id' => 'parent-1']);

        $id = $this->post("/api/admin/clients/{$client->id}/reports", [
            'title' => 'تقرير',
            'document' => $this->docx(),
            'pdf' => $this->pdf(),
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

    public function test_a_slash_in_the_title_is_stored_as_a_space_and_the_file_still_downloads(): void
    {
        $client = Client::factory()->create();

        $id = $this->post("/api/admin/clients/{$client->id}/reports", [
            'title' => 'تقرير/الربع:الأول',
            'document' => $this->docx(),
        ])->assertCreated()
            ->assertJsonPath('data.title', 'تقرير الربع الأول')
            ->json('data.id');

        $this->assertSame(1, $client->reports()->count());

        $response = $this->get("/api/admin/reports/{$id}/document");
        $response->assertOk();
        $disposition = (string) $response->headers->get('content-disposition');
        $this->assertStringNotContainsString('/', $disposition);
        $this->assertStringContainsString('.docx', $disposition);
    }

    public function test_a_title_made_only_of_forbidden_characters_is_rejected(): void
    {
        $client = Client::factory()->create();

        $this->post("/api/admin/clients/{$client->id}/reports", [
            'title' => '///',
            'document' => $this->docx(),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('title');

        $this->assertSame(0, $client->reports()->count());
    }

    public function test_an_older_copy_is_rejected_and_the_saved_file_stays(): void
    {
        $client = Client::factory()->create();
        $id = $this->post("/api/admin/clients/{$client->id}/reports", [
            'title' => 'تقرير',
            'document' => $this->docx(),
        ])->assertCreated()->json('data.id');
        $first = $this->getJson("/api/admin/reports/{$id}")->json('data.updated_at');

        $this->travel(5)->seconds();
        $this->post("/api/admin/reports/{$id}", [
            'title' => 'تقرير',
            'document' => UploadedFile::fake()->createWithContent('report.docx', 'PK newer'),
        ])->assertOk();

        $this->post("/api/admin/reports/{$id}", [
            'title' => 'تقرير قديم',
            'document' => UploadedFile::fake()->createWithContent('report.docx', 'PK stale'),
            'updated_at' => $first,
        ])->assertStatus(409)->assertJsonPath('code', 'report_conflict');

        $this->assertSame(1, $client->reports()->count());
        $this->assertSame('تقرير', $client->reports()->first()?->title);
        $this->assertSame('PK newer', Storage::disk('local')->get("reports/{$id}/report.docx"));
    }

    public function test_a_body_over_the_php_limit_is_a_clear_413(): void
    {
        config(['reports.max_body_bytes' => 100]);

        $this->call('POST', '/api/admin/reports/1', [], [], [], [
            'CONTENT_LENGTH' => '500',
            'HTTP_ACCEPT' => 'application/json',
        ])->assertStatus(413)
            ->assertJsonPath('message', 'الملف أكبر من الحد. صغّر الصور ثم أعد المحاولة.');
    }

    public function test_shrinking_a_report_picture_keeps_a_backup_and_the_row(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is not available.');
        }

        $client = Client::factory()->create();
        $report = $client->reports()->create([
            'title' => 'صور',
            'body' => 'متن',
            'document_path' => 'reports/shrink/report.docx',
        ]);
        Storage::disk('local')->put($report->document_path, $this->docxWithWideImage());

        $this->assertTrue(app(ShrinkDocxImages::class)->shrink('local', $report->document_path));

        Storage::disk('local')->assertExists($report->document_path);
        Storage::disk('local')->assertExists($report->document_path.'.bak');
        $this->assertNotNull($report->fresh());
        $this->assertSame('صور', $report->fresh()?->title);
    }

    private function docxWithWideImage(): string
    {
        $image = imagecreatetruecolor(2100, 20);
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        $path = tempnam(sys_get_temp_dir(), 'hoc-wide');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
        $zip->addFromString('word/media/image1.jpeg', $jpeg);
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }
}
