<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ProfilePdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfilePdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_profile_pdf_is_empty_until_uploaded(): void
    {
        $this->getJson('/api/profile-pdf')
            ->assertOk()
            ->assertJsonPath('data.url', null)
            ->assertJsonPath('data.name', null);

        $this->get('/api/profile-pdf/file')->assertNotFound();
    }

    public function test_guest_cannot_upload_profile_pdf(): void
    {
        Storage::fake('local');

        $this->post('/api/admin/ops-settings/profile-pdf', [
            'file' => $this->fakePdf('profile.pdf'),
        ], ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_admin_can_upload_replace_and_delete_profile_pdf(): void
    {
        Storage::fake('local');
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->post('/api/admin/ops-settings/profile-pdf', [
            'file' => $this->fakePdf('company-profile.pdf'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.name', 'company-profile.pdf');

        $this->getJson('/api/profile-pdf')
            ->assertOk()
            ->assertJsonPath('data.name', 'company-profile.pdf');
        $this->assertNotNull($this->getJson('/api/profile-pdf')->json('data.url'));
        $this->get('/api/profile-pdf/file')->assertOk();
        $this->assertNotNull(ProfilePdf::absolutePath());

        $this->post('/api/admin/ops-settings/profile-pdf', [
            'file' => $this->fakePdf('profile-v2.pdf'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.name', 'profile-v2.pdf');
        $this->assertCount(1, Storage::disk('local')->allFiles('profile'));

        $this->deleteJson('/api/admin/ops-settings/profile-pdf')
            ->assertOk()
            ->assertJsonPath('data.url', null);

        $this->get('/api/profile-pdf/file')->assertNotFound();
    }

    public function test_admin_upload_rejects_non_pdf(): void
    {
        Storage::fake('local');
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $this->post('/api/admin/ops-settings/profile-pdf', [
            'file' => UploadedFile::fake()->image('photo.png'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    private function fakePdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
        );
    }
}
