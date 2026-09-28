<?php

namespace Tests\Feature;

use App\Models\OpsSetting;
use App\Models\User;
use App\Services\GoogleDriveClient;
use App\Services\GoogleDriveUploader;
use App\Services\GoogleServiceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class GoogleDriveStorageAccountTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_ID = '123-abc.apps.googleusercontent.com';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://hoc.agency',
            'services.google.drive_parent_folder_id' => 'root-hoc',
            'services.google.drive_oauth_client_id' => null,
            'services.google.drive_oauth_client_secret' => null,
            'services.google.drive_refresh_token' => null,
            'services.google.drive_storage_email' => null,
        ]);
    }

    private function serviceAccount(): GoogleServiceAccount
    {
        $auth = Mockery::mock(GoogleServiceAccount::class);
        $auth->shouldReceive('configured')->andReturn(true);
        $auth->shouldReceive('configurationError')->andReturn(null);
        $auth->shouldReceive('accessToken')->andReturn('service-token');
        $auth->shouldReceive('clientEmail')->andReturn('hoc-ops@project.iam.gserviceaccount.com');
        $auth->shouldReceive('accessTokenFor')->andReturn(null);
        $this->app->instance(GoogleServiceAccount::class, $auth);

        return $auth;
    }

    private function connectStorageAccount(): void
    {
        $uploader = app(GoogleDriveUploader::class);
        $uploader->saveClient(self::CLIENT_ID, 'client-secret-value');
        $uploader->connect([
            'refresh_token' => 'refresh-1',
            'access_token' => 'user-token',
            'expires_in' => 3600,
        ], 'studio@gmail.com');
    }

    public function test_staff_save_the_oauth_client_and_get_the_google_consent_link(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/admin/drive/storage-account')
            ->assertOk()
            ->assertJsonPath('data.oauth_configured', false)
            ->assertJsonPath('data.connected', false)
            ->assertJsonPath('data.redirect_uri', 'https://hoc.agency/auth/google-drive/callback');

        $this->putJson('/api/admin/drive/storage-account/client', ['client_id' => 'not-a-client', 'client_secret' => 'client-secret-value'])
            ->assertStatus(422);

        $this->putJson('/api/admin/drive/storage-account/client', ['client_id' => self::CLIENT_ID, 'client_secret' => 'client-secret-value'])
            ->assertOk()
            ->assertJsonPath('data.oauth_configured', true)
            ->assertJsonPath('data.client_id', self::CLIENT_ID)
            ->assertJsonMissingPath('data.client_secret');

        $this->assertStringNotContainsString('client-secret-value', (string) OpsSetting::getValue('google_drive_oauth_client'));

        $url = $this->postJson('/api/admin/drive/storage-account/connect')
            ->assertOk()
            ->json('data.authorize_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        $this->assertSame(self::CLIENT_ID, $query['client_id']);
        $this->assertSame('https://hoc.agency/auth/google-drive/callback', $query['redirect_uri']);
        $this->assertSame(GoogleDriveUploader::SCOPE, $query['scope']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertNotEmpty($query['state']);
    }

    public function test_the_callback_stores_the_account_and_gives_it_edit_access_to_hoc_clients(): void
    {
        $this->serviceAccount();
        $uploader = app(GoogleDriveUploader::class);
        $uploader->saveClient(self::CLIENT_ID, 'client-secret-value');
        $state = $uploader->rememberState(User::factory()->admin()->create()->id);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'user-token',
                'refresh_token' => 'refresh-1',
                'expires_in' => 3599,
                'scope' => GoogleDriveUploader::SCOPE,
            ]),
            'https://www.googleapis.com/drive/v3/about*' => Http::response([
                'user' => ['emailAddress' => 'studio@gmail.com'],
                'storageQuota' => ['limit' => '16106127360', 'usage' => '1024'],
            ]),
            'https://www.googleapis.com/drive/v3/files/root-hoc/permissions*' => Http::response(['id' => 'perm-1']),
        ]);

        $this->get('/auth/google-drive/callback?code=code-1&state='.$state)
            ->assertRedirect('https://hoc.agency/dashboard/reports?drive=connected');

        $stored = (string) OpsSetting::getValue('google_drive_storage_account');
        $this->assertStringNotContainsString('refresh-1', $stored);
        $this->assertTrue($uploader->connected());
        $this->assertSame('studio@gmail.com', $uploader->email());

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/files/root-hoc/permissions')
            && $request->hasHeader('Authorization', 'Bearer service-token')
            && $request['emailAddress'] === 'studio@gmail.com'
            && $request['role'] === 'writer');

        // The state is single use.
        $this->get('/auth/google-drive/callback?code=code-1&state='.$state)
            ->assertRedirect('https://hoc.agency/dashboard/reports?drive_error=invalid_state');
    }

    public function test_the_callback_refuses_a_grant_without_drive_access(): void
    {
        $uploader = app(GoogleDriveUploader::class);
        $uploader->saveClient(self::CLIENT_ID, 'client-secret-value');
        $state = $uploader->rememberState(User::factory()->admin()->create()->id);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'user-token',
                'refresh_token' => 'refresh-1',
                'scope' => 'openid',
            ]),
        ]);

        $this->get('/auth/google-drive/callback?code=code-1&state='.$state)
            ->assertRedirect('https://hoc.agency/dashboard/reports?drive_error=scope');
        $this->assertFalse($uploader->connected());
    }

    public function test_files_upload_as_the_storage_account_inside_the_service_account_folder(): void
    {
        $auth = $this->serviceAccount();
        $this->connectStorageAccount();

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'user-token', 'expires_in' => 3599]);
            }
            if (str_contains($url, '/permissions')) {
                return Http::response(['id' => 'perm-1']);
            }
            if (str_contains($url, 'upload/drive/v3/files')) {
                return Http::response(['id' => 'file-1', 'webViewLink' => 'https://drive.google.com/file/d/file-1/view']);
            }

            return Http::response([
                'id' => 'report-folder',
                'parents' => ['client-folder'],
                'owners' => [['emailAddress' => 'hoc-ops@project.iam.gserviceaccount.com']],
            ]);
        });

        $file = (new GoogleDriveClient($auth, app(GoogleDriveUploader::class)))
            ->uploadFile('report-folder', 'report.docx', 'PK bytes', 'application/octet-stream');

        $this->assertSame('file-1', $file['id'] ?? null);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'upload/drive/v3/files')
            && $request->hasHeader('Authorization', 'Bearer user-token'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/files/report-folder/permissions')
            && $request->hasHeader('Authorization', 'Bearer service-token'));
    }

    public function test_without_a_storage_account_the_upload_explains_how_to_fix_it(): void
    {
        $auth = $this->serviceAccount();

        Http::fake([
            'https://www.googleapis.com/drive/v3/files/*' => Http::response([
                'id' => 'report-folder',
                'owners' => [['emailAddress' => 'hoc-ops@project.iam.gserviceaccount.com']],
            ]),
        ]);

        $drive = new GoogleDriveClient($auth, app(GoogleDriveUploader::class));

        $this->assertNull($drive->uploadFile('report-folder', 'report.docx', 'PK bytes', 'application/octet-stream'));
        $this->assertStringContainsString('Drive storage', (string) $drive->lastError());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'upload/drive'));
    }

    public function test_folders_are_still_created_by_the_service_account(): void
    {
        $auth = $this->serviceAccount();

        Http::fake(function (Request $request) {
            if ($request->method() === 'POST') {
                return Http::response(['id' => 'new-folder']);
            }
            if (str_contains($request->url(), '/files/client-folder')) {
                return Http::response(['id' => 'client-folder', 'owners' => [['emailAddress' => 'hoc-ops@project.iam.gserviceaccount.com']]]);
            }

            return Http::response(['files' => []]);
        });

        $folder = (new GoogleDriveClient($auth, app(GoogleDriveUploader::class)))->ensureFolderPath('client-folder', ['تقرير']);

        $this->assertSame('new-folder', $folder);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer service-token'));
    }

    public function test_google_returns_staff_to_the_dashboard_on_the_site_host(): void
    {
        config(['app.url' => 'https://api.hoc.agency']);

        $this->assertSame('https://hoc.agency/dashboard/reports', GoogleDriveUploader::dashboardUrl());
        $this->assertSame('https://api.hoc.agency/auth/google-drive/callback', GoogleDriveUploader::redirectUri());
    }

    public function test_disconnecting_forgets_the_account(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->connectStorageAccount();
        Http::fake(['https://oauth2.googleapis.com/revoke' => Http::response([])]);

        $this->deleteJson('/api/admin/drive/storage-account')
            ->assertOk()
            ->assertJsonPath('data.connected', false);

        $this->assertFalse(app(GoogleDriveUploader::class)->connected());
    }

    public function test_a_refresh_token_in_the_environment_counts_as_connected(): void
    {
        config(['services.google.drive_refresh_token' => 'env-refresh']);

        $this->assertTrue(app(GoogleDriveUploader::class)->connected());
    }
}
