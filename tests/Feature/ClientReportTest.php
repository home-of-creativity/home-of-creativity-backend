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

    public function test_a_report_requires_the_client_drive_folder(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $client = Client::factory()->create(['company_name' => 'دار الإبداع']);

        $this->post("/api/admin/clients/{$client->id}/reports", [
            'title' => 'تقرير',
            'header' => 'ترويسة',
            'footer' => 'تذييل',
            'body' => '<p>المتن</p>',
        ])->assertStatus(422);
    }

    public function test_a_report_uploads_the_pdf_and_attachment(): void
    {
        Storage::fake('public');
        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('ensureFolderPath')->andReturn('sub-folder');
            $mock->shouldReceive('uploadFile')->andReturn([
                'id' => 'file-1',
                'url' => 'https://drive.google.com/file/d/file-1/view',
            ]);
            $mock->shouldReceive('lastError')->andReturn(null);
        });

        Sanctum::actingAs(User::factory()->admin()->create());
        $client = Client::factory()->create([
            'company_name' => 'دار الإبداع',
            'google_drive_folder_id' => 'parent-1',
        ]);

        $this->post("/api/admin/clients/{$client->id}/reports", [
            'title' => 'تقرير الربع',
            'header' => 'ترويسة الشركة',
            'footer' => 'تذييل الشركة',
            'body' => '<p>المتن</p>',
            'attachments' => [UploadedFile::fake()->create('notes.pdf', 12, 'application/pdf')],
        ])->assertCreated()
            ->assertJsonPath('data.header', 'ترويسة الشركة')
            ->assertJsonPath('data.footer', 'تذييل الشركة')
            ->assertJsonPath('data.drive_url', 'https://drive.google.com/file/d/file-1/view')
            ->assertJsonPath('data.attachments.0.name', 'notes.pdf');
    }
}
