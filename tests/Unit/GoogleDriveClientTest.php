<?php

namespace Tests\Unit;

use App\Services\GoogleDriveClient;
use App\Services\GoogleServiceAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class GoogleDriveClientTest extends TestCase
{
    public function test_list_new_files_resolves_shortcuts_subfolders_and_company_parent(): void
    {
        config(['services.google.drive_parent_folder_id' => 'root-hoc']);

        $auth = Mockery::mock(GoogleServiceAccount::class);
        $auth->shouldReceive('configured')->andReturn(true);
        $auth->shouldReceive('configurationError')->andReturn(null);
        $auth->shouldReceive('accessToken')->andReturn('drive-token');

        Http::fake(function (Request $request) {
            $url = $request->url();
            parse_str((string) parse_url($url, PHP_URL_QUERY), $queryParams);
            $query = urldecode((string) ($queryParams['q'] ?? $request['q'] ?? ''));

            if (str_contains($url, '/files/task-folder')) {
                return Http::response(['id' => 'task-folder', 'parents' => ['company-folder']], 200);
            }

            if (str_contains($query, 'task-folder')) {
                return Http::response([
                    'files' => [
                        [
                            'id' => 'shortcut-1',
                            'name' => 'photo from album',
                            'mimeType' => 'application/vnd.google-apps.shortcut',
                            'shortcutDetails' => [
                                'targetId' => 'target-photo',
                                'targetMimeType' => 'image/jpeg',
                            ],
                        ],
                        [
                            'id' => 'nested',
                            'name' => 'shots',
                            'mimeType' => 'application/vnd.google-apps.folder',
                        ],
                    ],
                ], 200);
            }

            if (str_contains($query, 'nested')) {
                return Http::response([
                    'files' => [
                        ['id' => 'nested-png', 'name' => 'inside.png', 'mimeType' => 'image/png', 'md5Checksum' => 'abc'],
                    ],
                ], 200);
            }

            if (str_contains($query, 'company-folder')) {
                return Http::response([
                    'files' => [
                        ['id' => 'loose-jpg', 'name' => 'logo.jpg', 'mimeType' => 'image/jpeg'],
                    ],
                ], 200);
            }

            return Http::response(['files' => []], 200);
        });

        $files = (new GoogleDriveClient($auth))->listNewFiles('task-folder');
        $ids = array_column($files, 'id');

        $this->assertContains('target-photo', $ids);
        $this->assertNotContains('shortcut-1', $ids);
        $this->assertContains('nested-png', $ids);
        $this->assertContains('loose-jpg', $ids);
    }

    public function test_download_file_follows_shortcut_instead_of_exporting_pdf(): void
    {
        config(['services.google.drive_parent_folder_id' => 'root-hoc']);

        $auth = Mockery::mock(GoogleServiceAccount::class);
        $auth->shouldReceive('configured')->andReturn(true);
        $auth->shouldReceive('configurationError')->andReturn(null);
        $auth->shouldReceive('accessToken')->andReturn('drive-token');

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/export')) {
                return Http::response('should-not-export', 400);
            }

            if (str_contains($url, '/files/shortcut-1')) {
                return Http::response([
                    'id' => 'shortcut-1',
                    'mimeType' => 'application/vnd.google-apps.shortcut',
                    'shortcutDetails' => [
                        'targetId' => 'real-jpg',
                        'targetMimeType' => 'image/jpeg',
                    ],
                ], 200);
            }

            if (str_contains($url, '/files/real-jpg') && ($request['alt'] ?? null) === 'media') {
                return Http::response('JPEG-BYTES', 200);
            }

            if (str_contains($url, '/files/real-jpg')) {
                return Http::response(['id' => 'real-jpg', 'mimeType' => 'image/jpeg'], 200);
            }

            return Http::response('nope', 404);
        });

        $body = (new GoogleDriveClient($auth))->downloadFile('shortcut-1');

        $this->assertSame('JPEG-BYTES', $body);
        Http::assertNotSent(fn (Request $http): bool => str_contains($http->url(), '/export'));
    }

    public function test_is_under_parent_folder_accepts_hoc_client_tree_only(): void
    {
        config(['services.google.drive_parent_folder_id' => 'root-hoc']);

        $auth = Mockery::mock(GoogleServiceAccount::class);
        $auth->shouldReceive('configured')->andReturn(true);
        $auth->shouldReceive('configurationError')->andReturn(null);
        $auth->shouldReceive('accessToken')->andReturn('drive-token');

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/files/company-folder')) {
                return Http::response(['id' => 'company-folder', 'parents' => ['root-hoc']], 200);
            }

            if (str_contains($request->url(), '/files/other-folder')) {
                return Http::response(['id' => 'other-folder', 'parents' => ['someone-else']], 200);
            }

            return Http::response(['id' => 'x', 'parents' => []], 200);
        });

        $drive = new GoogleDriveClient($auth);

        $this->assertTrue($drive->isUnderParentFolder([
            'id' => 'logo.png',
            'parents' => ['root-hoc'],
        ]));
        $this->assertTrue($drive->isUnderParentFolder([
            'id' => 'cover.png',
            'parents' => ['company-folder'],
        ]));
        $this->assertFalse($drive->isUnderParentFolder([
            'id' => 'random.png',
            'parents' => ['other-folder'],
        ]));
    }

    public function test_list_changes_returns_files_and_the_new_page_token(): void
    {
        $auth = Mockery::mock(GoogleServiceAccount::class);
        $auth->shouldReceive('configured')->andReturn(true);
        $auth->shouldReceive('configurationError')->andReturn(null);
        $auth->shouldReceive('accessToken')->andReturn('drive-token');
        config(['services.google.drive_parent_folder_id' => 'root-hoc']);

        Http::fake([
            'https://www.googleapis.com/drive/v3/changes*' => Http::response([
                'newStartPageToken' => 'page-9',
                'changes' => [
                    ['removed' => true, 'fileId' => 'gone'],
                    [
                        'fileId' => 'file-1',
                        'file' => [
                            'id' => 'file-1',
                            'name' => 'logo.png',
                            'mimeType' => 'image/png',
                            'parents' => ['task-folder'],
                            'trashed' => false,
                        ],
                    ],
                ],
            ], 200),
        ]);

        $listed = (new GoogleDriveClient($auth))->listChanges('page-1');

        $this->assertSame('page-9', $listed['newPageToken'] ?? null);
        $this->assertSame('file-1', $listed['files'][0]['id'] ?? null);
        $this->assertSame(['task-folder'], $listed['files'][0]['parents'] ?? null);
    }
}
