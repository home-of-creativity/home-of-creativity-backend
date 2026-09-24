<?php

namespace Tests\Feature;

use App\Enums\StaffAbility;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Services\GoogleDriveClient;
use App\Services\GoogleServiceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriveFolderBrowserTest extends TestCase
{
    use RefreshDatabase;

    public function test_listing_without_a_parent_returns_every_visible_folder(): void
    {
        config(['services.google.drive_parent_folder_id' => 'parent-hoc']);
        $this->mock(GoogleServiceAccount::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('configurationError')->andReturn(null);
            $mock->shouldReceive('accessToken')->andReturn('token');
        });
        Http::fake([
            'https://www.googleapis.com/drive/v3/files*' => Http::response([
                'files' => [
                    ['id' => 'b', 'name' => 'Beta'],
                    ['id' => 'a', 'name' => 'Alpha'],
                ],
                'nextPageToken' => 'next',
            ]),
        ]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/admin/drive/folders')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'a')
            ->assertJsonPath('data.0.name', 'Alpha')
            ->assertJsonPath('data.1.id', 'b')
            ->assertJsonPath('meta.parent_id', null)
            ->assertJsonPath('meta.next_page_token', 'next');

        Http::assertSent(function ($request): bool {
            $query = $request['q'];

            return str_contains((string) $query, "mimeType = 'application/vnd.google-apps.folder'")
                && ! str_contains((string) $query, 'in parents')
                && ($request['corpora'] ?? null) === 'allDrives'
                && ($request['includeItemsFromAllDrives'] ?? null) === 'true';
        });
    }

    public function test_listing_a_parent_returns_only_its_children(): void
    {
        config(['services.google.drive_parent_folder_id' => 'parent-hoc']);
        $this->mock(GoogleServiceAccount::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('configurationError')->andReturn(null);
            $mock->shouldReceive('accessToken')->andReturn('token');
        });
        Http::fake([
            'https://www.googleapis.com/drive/v3/files*' => Http::response([
                'files' => [
                    ['id' => 'child', 'name' => 'داخل المجلد'],
                ],
            ]),
        ]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/admin/drive/folders?parent=main-1&page_token=tok')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'child')
            ->assertJsonPath('meta.parent_id', 'main-1');

        Http::assertSent(fn ($request): bool => str_contains((string) $request['q'], "'main-1' in parents")
            && ($request['pageToken'] ?? null) === 'tok');
    }

    public function test_staff_can_create_a_folder_inside_the_open_folder_and_assign_it(): void
    {
        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('createFolder')->once()->with('فرعي', 'main-1')->andReturn('created-folder-1');
            $mock->shouldReceive('lastError')->andReturn(null);
        });

        Sanctum::actingAs(User::factory()->admin()->create());
        $client = Client::factory()->create(['company_name' => 'دار الإبداع']);

        $this->postJson('/api/admin/drive/folders', [
            'name' => 'فرعي',
            'parent' => 'main-1',
        ])->assertCreated()
            ->assertJsonPath('data.id', 'created-folder-1')
            ->assertJsonPath('data.parent_id', 'main-1');

        $this->putJson("/api/admin/clients/{$client->id}/drive-folder", [
            'mode' => 'existing',
            'folder' => 'created-folder-1',
        ])->assertOk()
            ->assertJsonPath('data.google_drive_folder_id', 'created-folder-1');

        $this->assertSame('created-folder-1', $client->fresh()?->google_drive_folder_id);
    }

    public function test_creating_for_a_client_uses_the_given_name_and_parent(): void
    {
        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('createFolder')->once()->with('مجلد العميل', 'root-folder')->andReturn('leaf-9');
            $mock->shouldReceive('lastError')->andReturn(null);
        });

        Sanctum::actingAs(User::factory()->admin()->create());
        $client = Client::factory()->create();

        $this->putJson("/api/admin/clients/{$client->id}/drive-folder", [
            'mode' => 'create',
            'name' => 'مجلد العميل',
            'parent' => 'root-folder',
        ])->assertOk()
            ->assertJsonPath('data.google_drive_folder_id', 'leaf-9');
    }

    public function test_reports_staff_can_browse_and_assign_without_the_clients_ability(): void
    {
        $this->mock(GoogleDriveClient::class, function ($mock): void {
            $mock->shouldReceive('listFolders')->once()->with(null, null)->andReturn([
                'folders' => [['id' => 'seen', 'name' => 'ظاهر']],
                'next_page_token' => null,
            ]);
            $mock->shouldReceive('lastError')->andReturn(null);
        });

        $role = Role::query()->create([
            'name' => 'التقارير',
            'abilities' => [StaffAbility::OpsReports->value],
        ]);
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id]));
        $client = Client::factory()->create();

        $this->getJson('/api/admin/drive/folders')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'seen');

        $this->putJson("/api/admin/clients/{$client->id}/drive-folder", [
            'mode' => 'existing',
            'folder' => 'https://drive.google.com/drive/folders/picked-folder',
        ])->assertOk()
            ->assertJsonPath('data.google_drive_folder_id', 'picked-folder');
    }

    public function test_missing_drive_configuration_returns_422_and_other_staff_are_forbidden(): void
    {
        config(['services.google.drive_parent_folder_id' => '']);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/admin/drive/folders')->assertStatus(422);
        $this->postJson('/api/admin/drive/folders', [])->assertStatus(422);

        $role = Role::query()->create([
            'name' => 'الموظفون',
            'abilities' => [StaffAbility::OpsEmployees->value],
        ]);
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id]));

        $this->getJson('/api/admin/drive/folders')->assertForbidden();
        $this->postJson('/api/admin/drive/folders', ['name' => 'ممنوع'])->assertForbidden();
    }

    public function test_a_folder_without_a_parent_is_created_in_the_shared_drive(): void
    {
        config(['services.google.drive_parent_folder_id' => 'shared-parent']);
        $this->mock(GoogleServiceAccount::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('configurationError')->andReturn(null);
            $mock->shouldReceive('accessToken')->andReturn('token');
        });
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/files/shared-parent')) {
                return Http::response(['id' => 'shared-parent', 'driveId' => 'drive-1']);
            }
            if ($request->method() === 'GET') {
                return Http::response(['files' => []]);
            }

            return Http::response(['id' => 'new-folder'], 200);
        });

        $this->assertSame('new-folder', app(GoogleDriveClient::class)->createFolder('تقارير'));

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && ($request['parents'][0] ?? null) === 'shared-parent';
        });
    }

    public function test_a_user_owned_folder_is_written_as_that_user(): void
    {
        config(['services.google.drive_parent_folder_id' => 'user-folder']);
        $this->mock(GoogleServiceAccount::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('configurationError')->andReturn(null);
            $mock->shouldReceive('accessToken')->andReturn('service-token');
            $mock->shouldReceive('clientEmail')->andReturn('tech@hoc.test');
            $mock->shouldReceive('accessTokenFor')->once()->with('owner@hoc.test')->andReturn('owner-token');
        });
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/files/user-folder')) {
                return Http::response([
                    'id' => 'user-folder',
                    'owners' => [['emailAddress' => 'owner@hoc.test']],
                ]);
            }
            if ($request->method() === 'GET') {
                return Http::response(['files' => []]);
            }

            return Http::response(['id' => 'owned-folder']);
        });

        $this->assertSame('owned-folder', app(GoogleDriveClient::class)->createFolder('تقرير', 'user-folder'));

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer owner-token');
        });
    }
}
