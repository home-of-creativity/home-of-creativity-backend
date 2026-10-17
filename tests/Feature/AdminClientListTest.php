<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminClientListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
    }

    public function test_clients_sort_by_a_whitelisted_column_in_both_directions(): void
    {
        foreach (['QA-Beta', 'QA-Alpha', 'QA-Gamma'] as $name) {
            Client::factory()->create(['name' => $name, 'company_name' => $name.' Co']);
        }

        $asc = $this->getJson('/api/admin/clients?sort=name&dir=asc')->assertOk()->json('data.*.name');
        $this->assertSame(['QA-Alpha', 'QA-Beta', 'QA-Gamma'], $asc);

        $desc = $this->getJson('/api/admin/clients?sort=company_name&dir=desc')->assertOk()->json('data.*.name');
        $this->assertSame(['QA-Gamma', 'QA-Beta', 'QA-Alpha'], $desc);

        $newest = $this->getJson('/api/admin/clients')->assertOk()->json('data.*.name');
        $this->assertSame(['QA-Gamma', 'QA-Alpha', 'QA-Beta'], $newest, 'no sort keeps newest first');
    }

    public function test_an_unknown_sort_column_or_direction_is_rejected(): void
    {
        $this->getJson('/api/admin/clients?sort=password')->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson('/api/admin/clients?sort=name&dir=sideways')->assertUnprocessable()->assertJsonValidationErrors('dir');
    }

    public function test_a_single_client_is_shown_or_not_found(): void
    {
        $client = Client::factory()->create(['name' => 'QA-Show']);

        $this->getJson("/api/admin/clients/{$client->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $client->id)
            ->assertJsonPath('data.name', 'QA-Show');
        $this->getJson('/api/admin/clients/999999')->assertNotFound();
    }

    public function test_a_client_logo_upload_replaces_the_previous_file(): void
    {
        Storage::fake('public');
        $client = Client::factory()->create(['name' => 'QA-Logo']);

        $first = $this->post("/api/admin/clients/{$client->id}/logo", [
            'logo' => UploadedFile::fake()->image('logo.png', 120, 120),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertNotNull($first->json('data.logo_url'));
        $old = (string) $client->fresh()->logo_path;
        Storage::disk('public')->assertExists($old);

        $this->post("/api/admin/clients/{$client->id}/logo", [
            'logo' => UploadedFile::fake()->image('logo.webp', 120, 120),
        ], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists((string) $client->fresh()->logo_path);

        $this->post("/api/admin/clients/{$client->id}/logo", [
            'logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
    }
}
