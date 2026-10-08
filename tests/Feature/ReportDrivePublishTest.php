<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientReport;
use App\Models\User;
use App\Services\GoogleDriveClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportDrivePublishTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_updates_the_same_word_and_pdf_in_the_current_folder(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $client = Client::factory()->create(['google_drive_folder_id' => 'folder-a']);
        $uploads = [];
        $replacements = [];

        $this->mock(GoogleDriveClient::class, function ($mock) use (&$uploads, &$replacements): void {
            $mock->shouldReceive('lastError')->andReturn(null);
            $mock->shouldReceive('uploadFile')->times(2)->andReturnUsing(function (string $folder, string $name, string $contents, string $mime) use (&$uploads): array {
                $uploads[] = [$folder, $name, strlen($contents)];

                return [
                    'id' => str_ends_with($name, '.pdf') ? 'pdf-1' : 'doc-1',
                    'url' => 'https://drive.google.com/file/d/'.(str_ends_with($name, '.pdf') ? 'pdf-1' : 'doc-1').'/view',
                ];
            });
            $mock->shouldReceive('replaceFile')->times(2)->andReturnUsing(function (string $folder, string $id, string $name, string $contents, string $mime) use (&$replacements): array {
                $replacements[] = [$folder, $id, $name, strlen($contents)];

                return [
                    'id' => $id,
                    'url' => 'https://drive.google.com/file/d/'.$id.'/view',
                ];
            });
        });

        $created = $this->post("/api/admin/clients/{$client->id}/reports", [
            'title' => 'محضر',
            'document' => UploadedFile::fake()->createWithContent('report.docx', 'docx-bytes'),
            'pdf' => UploadedFile::fake()->createWithContent('report.pdf', 'pdf-bytes'),
            'publish' => '1',
        ]);

        $created->assertCreated()
            ->assertJsonPath('drive_error', null)
            ->assertJsonPath('data.drive_document_url', 'https://drive.google.com/file/d/doc-1/view')
            ->assertJsonPath('data.drive_url', 'https://drive.google.com/file/d/pdf-1/view')
            ->assertJsonPath('data.has_pdf', true);

        $this->assertSame('folder-a', $uploads[0][0]);
        $this->assertSame('محضر.docx', $uploads[0][1]);
        $this->assertGreaterThan(0, $uploads[0][2]);
        $this->assertSame('folder-a', $uploads[1][0]);
        $this->assertSame('محضر.pdf', $uploads[1][1]);
        $this->assertGreaterThan(0, $uploads[1][2]);

        $report = ClientReport::query()->firstOrFail();
        $this->assertSame('doc-1', $report->drive_document_id);
        $this->assertSame('pdf-1', $report->drive_file_id);
        $this->assertNotNull($report->published_at);

        $client->forceFill(['google_drive_folder_id' => 'folder-b'])->save();

        $this->post("/api/admin/reports/{$report->id}", [
            'title' => 'محضر جديد',
            'document' => UploadedFile::fake()->createWithContent('report.docx', 'docx-bytes-2'),
            'pdf' => UploadedFile::fake()->createWithContent('report.pdf', 'pdf-bytes-2'),
            'publish' => '1',
        ])->assertOk()
            ->assertJsonPath('drive_error', null);

        $this->assertSame(['folder-b', 'doc-1', 'محضر جديد.docx'], array_slice($replacements[0], 0, 3));
        $this->assertGreaterThan(0, $replacements[0][3]);
        $this->assertSame(['folder-b', 'pdf-1', 'محضر جديد.pdf'], array_slice($replacements[1], 0, 3));
        $this->assertGreaterThan(0, $replacements[1][3]);
        $this->assertSame('doc-1', $report->fresh()->drive_document_id);
        $this->assertSame('pdf-1', $report->fresh()->drive_file_id);
    }

    public function test_publish_without_a_pdf_does_not_upload(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $client = Client::factory()->create(['google_drive_folder_id' => 'folder-a']);
        $this->mock(GoogleDriveClient::class);

        $this->post("/api/admin/clients/{$client->id}/reports", [
            'title' => 'محضر',
            'document' => UploadedFile::fake()->createWithContent('report.docx', 'docx-bytes'),
            'publish' => '1',
        ])->assertCreated()
            ->assertJsonPath('drive_error', 'The PDF was not included. Publish again from the editor.');

        $this->assertNull(ClientReport::query()->firstOrFail()->drive_document_id);
    }

    public function test_drive_folder_search_and_lookup(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('lastError')->andReturn(null);
            $mock->shouldReceive('searchFolders')->once()->with('دمشق', \Mockery::any())->andReturn([
                'folders' => [['id' => 'f1', 'name' => 'دمشق']],
                'next_page_token' => null,
            ]);
            $mock->shouldReceive('folder')->once()->with('abc1234567')->andReturn([
                'id' => 'abc1234567',
                'name' => 'الرياض',
            ]);
        });

        $this->getJson('/api/admin/drive/folders?q='.rawurlencode('دمشق'))
            ->assertOk()
            ->assertJsonPath('data.0.id', 'f1')
            ->assertJsonPath('data.0.name', 'دمشق');

        $this->getJson('/api/admin/drive/folders?folder=abc1234567')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'الرياض');
    }

    public function test_short_drive_search_returns_nothing(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/drive/folders?q=d')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
