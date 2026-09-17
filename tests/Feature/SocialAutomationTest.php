<?php

namespace Tests\Feature;

use App\Enums\SocialAbility;
use App\Enums\SocialAccountStatus;
use App\Enums\SocialInboxKind;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostStatus;
use App\Enums\SocialPublishStatus;
use App\Models\SocialAccount;
use App\Models\SocialInboxItem;
use App\Models\SocialPost;
use App\Models\SocialPostMedia;
use App\Models\User;
use App\Services\SocialAccountSync;
use App\Services\SocialPublisher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SocialAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_open_social_accounts(): void
    {
        $this->getJson('/api/admin/social/accounts')->assertUnauthorized();
    }

    public function test_client_cannot_open_social_accounts(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/social/accounts')->assertForbidden();
    }

    public function test_admin_can_store_account_without_exposing_token(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/admin/social/accounts', [
            'platform' => 'facebook',
            'name' => 'HOC Page',
            'handle' => 'homeofcreativity',
            'page_id' => '111',
            'access_token' => 'secret-page-token',
        ])->assertCreated()
            ->assertJsonPath('data.platform', 'facebook')
            ->assertJsonPath('data.has_token', true)
            ->assertJsonPath('data.connection_status', 'connected')
            ->assertJsonMissingPath('data.access_token');

        $account = SocialAccount::query()->first();
        $this->assertNotNull($account);
        $this->assertSame('secret-page-token', $account->access_token);
    }

    public function test_accounts_index_keeps_only_facebook_graph_pages(): void
    {
        config([
            'services.facebook.access_token' => 'user-token',
            'services.facebook.app_id' => 'app-id',
            'services.facebook.app_secret' => 'app-secret',
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'data' => [[
                    'id' => '111',
                    'name' => 'Home of Creativity',
                    'access_token' => 'page-token',
                    'instagram_business_account' => [
                        'id' => '222',
                        'username' => 'homeofcreativity',
                        'name' => 'Home of Creativity',
                    ],
                ]],
            ], 200),
        ]);

        $admin = $this->admin();
        Sanctum::actingAs($admin);

        SocialAccount::factory()->recycle($admin)->create([
            'name' => 'E2EPage',
            'handle' => 'e2e-pagege',
            'page_id' => 'e2e-pagege',
            'platform' => SocialPlatform::Facebook,
            'connection_status' => SocialAccountStatus::Connected,
            'access_token' => 'test-token',
        ]);

        $this->getJson('/api/admin/social/accounts')
            ->assertOk()
            ->assertJsonFragment(['platform' => 'facebook', 'page_id' => '111', 'facebook_page_id' => '111', 'name' => 'Home of Creativity'])
            ->assertJsonFragment(['platform' => 'instagram', 'handle' => 'homeofcreativity', 'facebook_page_id' => '111'])
            ->assertJsonMissing(['name' => 'E2EPage'])
            ->assertJsonMissing(['handle' => 'e2e-pagege']);

        $this->assertSame(2, SocialAccount::query()->count());
        $this->assertDatabaseMissing('social_accounts', ['name' => 'E2EPage']);
    }

    public function test_accounts_index_reports_when_facebook_has_no_pages(): void
    {
        config([
            'services.facebook.access_token' => 'user-token',
            'services.facebook.app_id' => 'app-id',
            'services.facebook.app_secret' => 'app-secret',
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['data' => []], 200),
        ]);

        Sanctum::actingAs($this->admin());

        $this->getJson('/api/admin/social/accounts')
            ->assertOk()
            ->assertJsonPath('facebook_error', 'no_pages')
            ->assertJsonPath('data', []);

        $this->assertSame(0, SocialAccount::query()->count());
    }

    public function test_accounts_index_links_threads_from_instagram_and_threads_token(): void
    {
        config([
            'services.facebook.access_token' => 'user-token',
            'services.facebook.app_id' => 'app-id',
            'services.facebook.app_secret' => 'app-secret',
            'services.threads.access_token' => 'threads-user-token',
        ]);

        Http::fake([
            'https://graph.threads.net/*' => Http::response([
                'id' => '333',
                'username' => 'homeofcreativity',
                'name' => 'Home of Creativity',
            ], 200),
            'https://graph.facebook.com/*' => Http::response([
                'data' => [[
                    'id' => '111',
                    'name' => 'Home of Creativity',
                    'access_token' => 'page-token',
                    'instagram_business_account' => [
                        'id' => '222',
                        'username' => 'homeofcreativity',
                        'name' => 'Home of Creativity',
                    ],
                ]],
            ], 200),
        ]);

        Sanctum::actingAs($this->admin());

        $this->getJson('/api/admin/social/accounts')
            ->assertOk()
            ->assertJsonFragment(['platform' => 'facebook', 'page_id' => '111'])
            ->assertJsonFragment(['platform' => 'instagram', 'page_id' => '222'])
            ->assertJsonFragment([
                'platform' => 'threads',
                'page_id' => '333',
                'facebook_page_id' => '111',
                'handle' => 'homeofcreativity',
                'connection_status' => SocialAccountStatus::Connected->value,
                'has_token' => true,
            ])
            ->assertJsonPath('threads_configured', true);

        $this->assertSame(3, SocialAccount::query()->count());
        $threads = SocialAccount::query()->where('platform', SocialPlatform::Threads)->first();
        $this->assertNotNull($threads);
        $this->assertSame('threads-user-token', $threads->access_token);
    }

    public function test_accounts_index_discovers_threads_without_token(): void
    {
        config([
            'services.facebook.access_token' => 'user-token',
            'services.facebook.app_id' => 'app-id',
            'services.facebook.app_secret' => 'app-secret',
            'services.threads.access_token' => '',
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'connected_threads_user')
                || str_contains($request->url(), 'instagram_backed_threads_user')) {
                return Http::response([
                    'data' => [[
                        'threads_user_id' => '333',
                        'username' => 'homeofcreativity',
                        'name' => 'Home of Creativity',
                    ]],
                ], 200);
            }

            return Http::response([
                'data' => [[
                    'id' => '111',
                    'name' => 'Home of Creativity',
                    'access_token' => 'page-token',
                    'instagram_business_account' => [
                        'id' => '222',
                        'username' => 'homeofcreativity',
                        'name' => 'Home of Creativity',
                    ],
                ]],
            ], 200);
        });

        Sanctum::actingAs($this->admin());

        $this->getJson('/api/admin/social/accounts')
            ->assertOk()
            ->assertJsonFragment([
                'platform' => 'threads',
                'page_id' => '333',
                'facebook_page_id' => '111',
                'connection_status' => SocialAccountStatus::Error->value,
                'last_error' => 'threads_token_missing',
                'has_token' => false,
            ]);
    }

    public function test_threads_connect_uses_hoc_agency_callback(): void
    {
        config([
            'services.threads.app_id' => 'threads-app-id',
            'services.threads.app_secret' => 'threads-app-secret',
        ]);

        Sanctum::actingAs($this->admin());

        $response = $this->getJson('/api/admin/social/threads/connect')
            ->assertOk()
            ->assertJsonPath('data.redirect_uri', 'https://hoc.agency/auth/threads/callback');

        $authorizeUrl = $response->json('data.authorize_url');
        $this->assertIsString($authorizeUrl);

        $query = [];
        parse_str(parse_url($authorizeUrl, PHP_URL_QUERY) ?: '', $query);

        $this->assertSame('threads-app-id', $query['client_id'] ?? null);
        $this->assertSame('https://hoc.agency/auth/threads/callback', $query['redirect_uri'] ?? null);
        $this->assertSame('code', $query['response_type'] ?? null);
        $this->assertNotEmpty($query['state'] ?? null);
        $this->assertStringContainsString('threads_basic', (string) ($query['scope'] ?? ''));
        $this->assertStringContainsString('threads_content_publish', (string) ($query['scope'] ?? ''));
        $this->assertStringStartsWith('https://threads.net/oauth/authorize', $authorizeUrl);
    }

    public function test_threads_connect_requires_oauth_credentials(): void
    {
        config([
            'services.threads.app_id' => '',
            'services.threads.app_secret' => '',
        ]);

        Sanctum::actingAs($this->admin());

        $this->getJson('/api/admin/social/threads/connect')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Threads OAuth is not configured.');
    }

    public function test_guest_cannot_start_threads_oauth(): void
    {
        $this->getJson('/api/admin/social/threads/connect')->assertUnauthorized();
    }

    public function test_threads_oauth_callback_stores_long_lived_token(): void
    {
        config([
            'services.threads.app_id' => 'threads-app-id',
            'services.threads.app_secret' => 'threads-app-secret',
        ]);

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/oauth/access_token')) {
                return Http::response([
                    'access_token' => 'short-lived-threads',
                    'expires_in' => 3600,
                ], 200);
            }
            if (str_contains($url, '/access_token')) {
                return Http::response([
                    'access_token' => 'long-lived-threads',
                    'expires_in' => 5184000,
                ], 200);
            }
            if (str_contains($url, '/me')) {
                return Http::response([
                    'id' => '333',
                    'username' => 'homeofcreativity',
                    'name' => 'Home of Creativity',
                ], 200);
            }

            return Http::response(['error' => ['message' => 'unexpected']], 500);
        });

        $admin = $this->admin();
        Cache::put('threads_oauth_state:oauth-state', ['user_id' => $admin->id], 600);

        $this->get('/auth/threads/callback?code=auth-code&state=oauth-state')
            ->assertRedirect('https://hoc.agency/staff/social/accounts?threads=connected');

        $account = SocialAccount::query()->where('platform', SocialPlatform::Threads)->first();
        $this->assertNotNull($account);
        $this->assertSame('333', $account->page_id);
        $this->assertSame('homeofcreativity', $account->handle);
        $this->assertSame('long-lived-threads', $account->access_token);
        $this->assertSame(SocialAccountStatus::Connected, $account->connection_status);
        $this->assertTrue($account->token_expires_at?->greaterThan(now()->addDays(50)) ?? false);
        $this->assertNull(Cache::get('threads_oauth_state:oauth-state'));

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/oauth/access_token')
                && $request['redirect_uri'] === 'https://hoc.agency/auth/threads/callback'
                && $request['code'] === 'auth-code';
        });
    }

    public function test_threads_oauth_callback_rejects_invalid_state(): void
    {
        $this->get('/auth/threads/callback?code=auth-code&state=missing')
            ->assertRedirect('https://hoc.agency/staff/social/accounts?threads_error=invalid_state');
    }

    public function test_facebook_sync_keeps_oauth_threads_account(): void
    {
        config([
            'services.facebook.access_token' => 'user-token',
            'services.facebook.app_id' => 'app-id',
            'services.facebook.app_secret' => 'app-secret',
            'services.threads.access_token' => '',
        ]);

        $admin = $this->admin();
        SocialAccount::factory()->recycle($admin)->create([
            'platform' => SocialPlatform::Threads,
            'name' => 'Home of Creativity',
            'handle' => 'homeofcreativity',
            'page_id' => '333',
            'connection_status' => SocialAccountStatus::Connected,
            'access_token' => 'oauth-threads-token',
        ]);

        Http::fake([
            'https://graph.threads.net/*' => Http::response([
                'id' => '333',
                'username' => 'homeofcreativity',
                'name' => 'Home of Creativity',
            ], 200),
            'https://graph.facebook.com/*' => Http::response([
                'data' => [[
                    'id' => '111',
                    'name' => 'Home of Creativity',
                    'access_token' => 'page-token',
                    'instagram_business_account' => [
                        'id' => '222',
                        'username' => 'homeofcreativity',
                        'name' => 'Home of Creativity',
                    ],
                ]],
            ], 200),
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/social/accounts')
            ->assertOk()
            ->assertJsonFragment([
                'platform' => 'threads',
                'page_id' => '333',
                'connection_status' => SocialAccountStatus::Connected->value,
                'has_token' => true,
            ]);

        $threads = SocialAccount::query()->where('platform', SocialPlatform::Threads)->first();
        $this->assertNotNull($threads);
        $this->assertSame('oauth-threads-token', $threads->access_token);
        $this->assertSame(SocialAccountStatus::Connected, $threads->connection_status);
        $this->assertNotSame('page_not_in_token', $threads->last_error);
    }

    public function test_admin_can_publish_text_to_threads(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, 'threads_publish')) {
                return Http::response(['id' => 'th_99'], 200);
            }
            if ($request->method() === 'POST' && str_contains($url, '/threads')) {
                return Http::response(['id' => 'th_container'], 200);
            }
            if (str_contains($url, 'th_container')) {
                return Http::response(['status' => 'FINISHED'], 200);
            }

            return Http::response(['id' => 'ok'], 200);
        });

        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $account = SocialAccount::factory()->connected()->recycle($admin)->create([
            'platform' => SocialPlatform::Threads,
            'name' => 'HOC Threads',
            'handle' => 'homeofcreativity',
            'page_id' => '333',
            'facebook_page_id' => '111',
            'access_token' => 'threads-user-token',
        ]);

        $this->post('/api/admin/social/posts', [
            'body' => 'Hello Threads.',
            'account_ids' => [$account->id],
            'intent' => 'publish',
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', SocialPostStatus::Published->value);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), '/threads')
            && ! str_contains($request->url(), 'threads_publish')
            && ($request['media_type'] ?? null) === 'TEXT'
            && ($request['text'] ?? null) === 'Hello Threads.');
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), 'threads_publish')
            && ($request['creation_id'] ?? null) === 'th_container');
    }

    public function test_account_edits_toggle_and_delete_survive_facebook_sync(): void
    {
        config([
            'services.facebook.access_token' => 'user-token',
            'services.facebook.app_id' => 'app-id',
            'services.facebook.app_secret' => 'app-secret',
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'data' => [[
                    'id' => '111',
                    'name' => 'Home of Creativity',
                    'access_token' => 'page-token',
                ]],
            ], 200),
        ]);

        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/social/accounts')
            ->assertOk()
            ->assertJsonPath('data.0.page_id', '111');

        $account = SocialAccount::query()->first();
        $this->assertNotNull($account);

        $this->putJson("/api/admin/social/accounts/{$account->id}", [
            'name' => 'أبو شاكر',
        ])->assertOk();

        $this->postJson("/api/admin/social/accounts/{$account->id}/toggle")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->getJson('/api/admin/social/accounts')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'أبو شاكر')
            ->assertJsonPath('data.0.is_active', false);

        $this->deleteJson("/api/admin/social/accounts/{$account->id}")->assertOk();

        $this->getJson('/api/admin/social/accounts')
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->assertDatabaseHas('social_accounts', [
            'page_id' => '111',
            'connection_status' => SocialAccountStatus::Disconnected->value,
            'is_active' => false,
            'last_error' => 'user_disconnected',
        ]);
        $this->assertSame(0, app(SocialAccountSync::class)->syncFromFacebook($admin->id));
    }

    public function test_create_permission_cannot_manage_accounts(): void
    {
        $admin = $this->admin([SocialAbility::Create->value]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/social/accounts', [
            'platform' => 'instagram',
            'name' => 'HOC IG',
        ])->assertForbidden();
    }

    public function test_admin_can_create_draft_and_publish_to_facebook(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['id' => 'fb_99'], 200),
        ]);

        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $account = SocialAccount::factory()->connected()->recycle($admin)->create();

        $response = $this->post('/api/admin/social/posts', [
            'body' => 'Launch day.',
            'account_ids' => [$account->id],
            'intent' => 'publish',
            'media' => [UploadedFile::fake()->image('hero.jpg')],
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.status', SocialPostStatus::Published->value)
            ->assertJsonPath('data.created_by.name', $admin->name)
            ->assertJsonPath('data.approved_by.name', $admin->name);

        $post = SocialPost::query()->first();
        $this->assertNotNull($post);
        $this->assertSame(SocialPublishStatus::Published, $post->targets()->first()?->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/photos'));
    }

    public function test_admin_can_publish_image_to_instagram(): void
    {
        Storage::fake('public');
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'rupload.facebook.com')) {
                return Http::response(['success' => true, 'message' => 'Upload successful.'], 200);
            }
            if (str_contains($url, '/photos')) {
                return Http::response(['id' => 'fb_photo'], 200);
            }
            if (str_contains($url, 'fb_photo')) {
                return Http::response(['images' => [['source' => 'https://cdn.example.test/photo.jpg', 'width' => 1080]]], 200);
            }
            if (str_contains($url, '/media_publish')) {
                return Http::response(['id' => 'ig_88'], 200);
            }
            if (str_contains($url, '/media')) {
                return Http::response(['id' => 'ig_container'], 200);
            }
            if (str_contains($url, 'ig_container')) {
                return Http::response(['status_code' => 'FINISHED'], 200);
            }

            return Http::response(['id' => 'ok'], 200);
        });

        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $account = SocialAccount::factory()->connected()->recycle($admin)->create([
            'platform' => SocialPlatform::Instagram,
            'name' => 'Damastech.ae',
            'page_id' => '222',
            'facebook_page_id' => '111',
        ]);

        $this->post('/api/admin/social/posts', [
            'body' => 'اهلا ومرحبا',
            'account_ids' => [$account->id],
            'intent' => 'publish',
            'media' => [UploadedFile::fake()->image('hero.jpg')],
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', SocialPostStatus::Published->value);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/photos'));
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/media')
            && ! str_contains($request->url(), 'media_publish')
            && ($request['media_type'] ?? null) === 'IMAGE'
            && ($request['image_url'] ?? null) === 'https://cdn.example.test/photo.jpg'
            && ! isset($request['upload_type']));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'rupload.facebook.com'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/media_publish'));
    }

    public function test_admin_can_publish_video_to_instagram_without_remote_fetch(): void
    {
        Storage::fake('public');
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'rupload.facebook.com')) {
                return Http::response(['success' => true, 'message' => 'Upload successful.'], 200);
            }
            if (str_contains($url, '/media_publish')) {
                return Http::response(['id' => 'ig_reel'], 200);
            }
            if (str_contains($url, '/media')) {
                return Http::response(['id' => 'ig_container'], 200);
            }
            if (str_contains($url, 'ig_container')) {
                return Http::response(['status_code' => 'FINISHED'], 200);
            }

            return Http::response(['id' => 'ok'], 200);
        });

        $admin = $this->admin();
        $account = SocialAccount::factory()->connected()->recycle($admin)->create([
            'platform' => SocialPlatform::Instagram,
            'name' => 'Damastech.ae',
            'page_id' => '222',
            'facebook_page_id' => '111',
        ]);

        Storage::disk('public')->put('social/posts/clip.mp4', str_repeat('m', 2048));
        $post = SocialPost::factory()->recycle($admin)->create([
            'created_by' => $admin->id,
            'placement' => 'reel',
            'body' => 'Reel cut.',
        ]);
        SocialPostMedia::query()->create([
            'social_post_id' => $post->id,
            'path' => 'social/posts/clip.mp4',
            'original_name' => 'clip.mp4',
            'mime' => 'video/mp4',
            'kind' => 'video',
            'sort_order' => 1,
        ]);
        $post->accounts()->attach($account->id, ['status' => SocialPublishStatus::Pending->value]);

        $result = app(SocialPublisher::class)->publish($account, $post->fresh(['media']));
        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertSame('ig_reel', $result['external_id']);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/media')
            && ! str_contains($request->url(), 'media_publish')
            && $request['media_type'] === 'REELS'
            && $request['upload_type'] === 'resumable'
            && ! isset($request['video_url']));
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), 'rupload.facebook.com/ig-api-upload')
            && str_contains(implode(';', $request->header('Content-Type')), 'application/octet-stream')
            && ($request->header('file_size')[0] ?? null) === '2048'
            && ($request->header('offset')[0] ?? null) === '0');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/videos') || str_contains($request->url(), '/photos'));
    }

    public function test_instagram_reel_upload_is_chunked_for_large_files(): void
    {
        Storage::fake('public');
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'rupload.facebook.com')) {
                return Http::response(['success' => true], 200);
            }
            if (str_contains($url, '/media_publish')) {
                return Http::response(['id' => 'ig_reel'], 200);
            }
            if (str_contains($url, '/media')) {
                return Http::response(['id' => 'ig_container'], 200);
            }
            if (str_contains($url, 'ig_container')) {
                return Http::response(['status_code' => 'FINISHED'], 200);
            }

            return Http::response(['id' => 'ok'], 200);
        });

        $admin = $this->admin();
        $account = SocialAccount::factory()->connected()->recycle($admin)->create([
            'platform' => SocialPlatform::Instagram,
            'page_id' => '222',
            'facebook_page_id' => '111',
        ]);

        $bytes = (4 * 1024 * 1024) + 32;
        Storage::disk('public')->put('social/posts/long.mp4', str_repeat('m', $bytes));
        $post = SocialPost::factory()->recycle($admin)->create([
            'created_by' => $admin->id,
            'placement' => 'reel',
            'body' => 'Long reel.',
        ]);
        SocialPostMedia::query()->create([
            'social_post_id' => $post->id,
            'path' => 'social/posts/long.mp4',
            'original_name' => 'long.mp4',
            'mime' => 'video/mp4',
            'kind' => 'video',
            'sort_order' => 1,
        ]);
        $post->accounts()->attach($account->id, ['status' => SocialPublishStatus::Pending->value]);

        $result = app(SocialPublisher::class)->publish($account, $post->fresh(['media']));
        $this->assertTrue($result['ok'], (string) $result['error']);

        $uploads = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'rupload.facebook.com'))
            ->values();
        $this->assertCount(2, $uploads);
        $this->assertSame('0', $uploads[0][0]->header('offset')[0] ?? null);
        $this->assertSame((string) $bytes, $uploads[0][0]->header('file_size')[0] ?? null);
        $this->assertSame((string) (4 * 1024 * 1024), $uploads[1][0]->header('offset')[0] ?? null);
    }

    public function test_instagram_reel_surfaces_rupload_debug_info_on_400(): void
    {
        Storage::fake('public');
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'rupload.facebook.com')) {
                return Http::response([
                    'debug_info' => [
                        'retriable' => false,
                        'type' => 'ProcessingFailedError',
                        'message' => 'File size does not match',
                    ],
                ], 400);
            }
            if (str_contains($url, '/media')) {
                return Http::response(['id' => 'ig_container'], 200);
            }

            return Http::response(['id' => 'ok'], 200);
        });

        $admin = $this->admin();
        $account = SocialAccount::factory()->connected()->recycle($admin)->create([
            'platform' => SocialPlatform::Instagram,
            'name' => 'Damastech.ae',
            'page_id' => '222',
            'facebook_page_id' => '111',
        ]);

        Storage::disk('public')->put('social/posts/clip.mp4', str_repeat('m', 2048));
        $post = SocialPost::factory()->recycle($admin)->create([
            'created_by' => $admin->id,
            'placement' => 'reel',
            'body' => 'Reel cut.',
        ]);
        SocialPostMedia::query()->create([
            'social_post_id' => $post->id,
            'path' => 'social/posts/clip.mp4',
            'original_name' => 'clip.mp4',
            'mime' => 'video/mp4',
            'kind' => 'video',
            'sort_order' => 1,
        ]);
        $post->accounts()->attach($account->id, ['status' => SocialPublishStatus::Pending->value]);

        $result = app(SocialPublisher::class)->publish($account, $post->fresh(['media']));
        $this->assertFalse($result['ok']);
        $this->assertSame('File size does not match', $result['error']);
    }

    public function test_scheduled_post_publishes_when_due(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['id' => 'fb_100'], 200),
        ]);

        $admin = $this->admin();
        $account = SocialAccount::factory()->connected()->recycle($admin)->create();
        $post = SocialPost::factory()->recycle($admin)->create([
            'created_by' => $admin->id,
            'status' => SocialPostStatus::Scheduled,
            'scheduled_at' => now()->subMinute(),
        ]);
        $post->accounts()->attach($account->id, ['status' => SocialPublishStatus::Pending->value]);

        $this->artisan('social:publish-due')->assertSuccessful();

        $this->assertSame(SocialPostStatus::Published, $post->fresh()->status);
    }

    public function test_creator_can_schedule_without_approval(): void
    {
        $editor = $this->admin([SocialAbility::Create->value]);
        Sanctum::actingAs($editor);

        $account = SocialAccount::factory()->connected()->recycle($editor)->create();

        $this->postJson('/api/admin/social/posts', [
            'body' => 'Later in Damascus.',
            'account_ids' => [$account->id],
            'intent' => 'schedule',
            'scheduled_at' => now('Asia/Damascus')->addHour()->format('Y-m-d\\TH:i'),
        ])->assertCreated()
            ->assertJsonPath('data.status', SocialPostStatus::Scheduled->value)
            ->assertJsonPath('data.approved_by', null);
    }

    public function test_due_schedule_publishes_without_approval(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['id' => 'fb_due'], 200),
        ]);

        $editor = $this->admin([SocialAbility::Create->value]);
        Sanctum::actingAs($editor);

        $account = SocialAccount::factory()->connected()->recycle($editor)->create();

        $this->post('/api/admin/social/posts', [
            'body' => 'Due now.',
            'account_ids' => [$account->id],
            'intent' => 'schedule',
            'scheduled_at' => now('Asia/Damascus')->subMinute()->format('Y-m-d\\TH:i'),
            'media' => [UploadedFile::fake()->image('due.jpg')],
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.status', SocialPostStatus::Published->value)
            ->assertJsonPath('data.approved_by', null);
    }

    public function test_posts_index_publishes_due_scheduled_posts(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['id' => 'fb_index'], 200),
        ]);

        $editor = $this->admin([SocialAbility::Create->value]);
        Sanctum::actingAs($editor);

        $account = SocialAccount::factory()->connected()->recycle($editor)->create();
        $post = SocialPost::factory()->recycle($editor)->create([
            'created_by' => $editor->id,
            'status' => SocialPostStatus::Scheduled,
            'scheduled_at' => now()->subMinute(),
        ]);
        $post->accounts()->attach($account->id, ['status' => SocialPublishStatus::Pending->value]);

        $this->getJson('/api/admin/social/posts')
            ->assertOk()
            ->assertJsonPath('data.0.status', SocialPostStatus::Published->value);

        $this->assertSame(SocialPostStatus::Published, $post->fresh()->status);
    }

    public function test_admin_can_filter_posts_by_account_and_placement(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $abuShaker = SocialAccount::factory()->connected()->recycle($admin)->create(['page_id' => 'page-shaker']);
        $abuAdnan = SocialAccount::factory()->connected()->recycle($admin)->create(['page_id' => 'page-adnan']);

        $feed = SocialPost::factory()->recycle($admin)->create([
            'created_by' => $admin->id,
            'body' => 'Abu Shaker feed',
            'placement' => 'feed',
            'status' => SocialPostStatus::Published,
            'published_at' => now(),
        ]);
        $feed->accounts()->attach($abuShaker->id, ['status' => SocialPublishStatus::Published->value]);

        $reel = SocialPost::factory()->recycle($admin)->create([
            'created_by' => $admin->id,
            'body' => 'Abu Shaker reel',
            'placement' => 'reel',
            'status' => SocialPostStatus::Published,
            'published_at' => now(),
        ]);
        $reel->accounts()->attach($abuShaker->id, ['status' => SocialPublishStatus::Published->value]);

        $otherPage = SocialPost::factory()->recycle($admin)->create([
            'created_by' => $admin->id,
            'body' => 'Abu Adnan feed',
            'placement' => 'feed',
            'status' => SocialPostStatus::Published,
            'published_at' => now(),
        ]);
        $otherPage->accounts()->attach($abuAdnan->id, ['status' => SocialPublishStatus::Published->value]);

        $this->getJson("/api/admin/social/posts?status=published&account_id={$abuShaker->id}&placement=feed")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'Abu Shaker feed');
    }

    public function test_scheduled_at_keeps_syria_wall_clock(): void
    {
        $editor = $this->admin([SocialAbility::Create->value]);
        Sanctum::actingAs($editor);

        $account = SocialAccount::factory()->connected()->recycle($editor)->create();

        $response = $this->postJson('/api/admin/social/posts', [
            'body' => 'Four twenty eight.',
            'account_ids' => [$account->id],
            'intent' => 'schedule',
            'scheduled_at' => '2026-09-12T16:28',
        ])->assertCreated();

        $scheduled = $response->json('data.scheduled_at');
        $this->assertIsString($scheduled);
        $this->assertTrue(
            Carbon::parse($scheduled)->equalTo(Carbon::parse('2026-09-12 13:28:00', 'UTC'))
        );
    }

    public function test_posts_index_removes_posts_deleted_on_facebook(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Unsupported get request. Object with ID does not exist',
                    'code' => 100,
                    'error_subcode' => 33,
                ],
            ], 400),
        ]);

        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $account = SocialAccount::factory()->connected()->recycle($admin)->create();
        $post = SocialPost::factory()->recycle($admin)->create([
            'created_by' => $admin->id,
            'status' => SocialPostStatus::Published,
            'published_at' => now(),
        ]);
        $post->accounts()->attach($account->id, [
            'status' => SocialPublishStatus::Published->value,
            'external_id' => 'fb_gone',
        ]);

        $this->getJson('/api/admin/social/posts')
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->assertModelMissing($post);
    }

    public function test_posts_index_prunes_deleted_facebook_posts_for_filtered_account(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Unsupported get request. Object with ID does not exist',
                    'code' => 100,
                    'error_subcode' => 33,
                ],
            ], 400),
        ]);

        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $keptAccount = SocialAccount::factory()->connected()->recycle($admin)->create(['page_id' => 'page-kept']);
        $prunedAccount = SocialAccount::factory()->connected()->recycle($admin)->create(['page_id' => 'page-gone']);

        $keptPost = SocialPost::factory()->recycle($admin)->create([
            'created_by' => $admin->id,
            'status' => SocialPostStatus::Published,
            'published_at' => now(),
        ]);
        $keptPost->accounts()->attach($keptAccount->id, [
            'status' => SocialPublishStatus::Published->value,
            'external_id' => 'fb_other',
        ]);

        $gonePost = SocialPost::factory()->recycle($admin)->create([
            'created_by' => $admin->id,
            'status' => SocialPostStatus::Published,
            'published_at' => now(),
        ]);
        $gonePost->accounts()->attach($prunedAccount->id, [
            'status' => SocialPublishStatus::Published->value,
            'external_id' => 'fb_gone',
        ]);

        $this->getJson("/api/admin/social/posts?account_id={$prunedAccount->id}")
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->assertModelMissing($gonePost);
        $this->assertModelExists($keptPost);
    }

    public function test_published_post_can_be_edited_on_facebook(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['success' => true], 200),
        ]);

        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $account = SocialAccount::factory()->connected()->recycle($admin)->create();
        $post = SocialPost::factory()->recycle($admin)->create([
            'created_by' => $admin->id,
            'status' => SocialPostStatus::Published,
            'published_at' => now(),
        ]);
        $post->accounts()->attach($account->id, [
            'status' => SocialPublishStatus::Published->value,
            'external_id' => 'fb_99',
        ]);

        $this->putJson("/api/admin/social/posts/{$post->id}", [
            'body' => 'Changed',
            'account_ids' => [$account->id],
        ])->assertOk()
            ->assertJsonPath('data.status', SocialPostStatus::Published->value)
            ->assertJsonPath('data.body', 'Changed')
            ->assertJsonPath('data.is_editable', true);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'fb_99')
            && $request->method() === 'POST'
            && $request['message'] === 'Changed');
    }

    public function test_published_post_can_be_deleted_from_facebook(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['success' => true], 200),
        ]);

        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $account = SocialAccount::factory()->connected()->recycle($admin)->create();
        $post = SocialPost::factory()->recycle($admin)->create([
            'created_by' => $admin->id,
            'status' => SocialPostStatus::Published,
            'published_at' => now(),
        ]);
        $post->accounts()->attach($account->id, [
            'status' => SocialPublishStatus::Published->value,
            'external_id' => 'fb_99',
        ]);

        $this->deleteJson("/api/admin/social/posts/{$post->id}")
            ->assertOk();

        $this->assertModelMissing($post);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(), 'fb_99'));
    }

    public function test_delete_succeeds_when_facebook_object_is_already_gone(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => "Unsupported delete request. Object with ID '922626523825998' does not exist, cannot be loaded due to missing permissions, or does not support this operation.",
                    'type' => 'GraphMethodException',
                    'code' => 100,
                    'error_subcode' => 33,
                ],
            ], 400),
        ]);

        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $account = SocialAccount::factory()->connected()->recycle($admin)->create([
            'name' => 'أبو شاكر',
            'page_id' => '111',
        ]);
        $post = SocialPost::factory()->recycle($admin)->create([
            'created_by' => $admin->id,
            'status' => SocialPostStatus::Published,
            'published_at' => now(),
        ]);
        $post->accounts()->attach($account->id, [
            'status' => SocialPublishStatus::Published->value,
            'external_id' => '922626523825998',
        ]);

        $this->deleteJson("/api/admin/social/posts/{$post->id}")
            ->assertOk();

        $this->assertModelMissing($post);
    }

    public function test_inbox_sync_imports_published_post_comments(): void
    {
        Http::fake([
            'https://graph.facebook.com/*published_posts*' => Http::response([
                'data' => [[
                    'id' => 'post_1',
                    'message' => 'Launch day.',
                    'permalink_url' => 'https://facebook.com/post_1',
                    'full_picture' => 'https://cdn.test/post.jpg',
                    'comments' => [
                        'data' => [[
                            'id' => 'cmt_live',
                            'message' => '🤗🤗',
                            'from' => ['id' => 'u1', 'name' => 'Guest'],
                            'created_time' => now()->toIso8601String(),
                        ]],
                    ],
                ]],
            ], 200),
            'https://graph.facebook.com/*' => Http::response(['data' => []], 200),
        ]);

        $admin = $this->admin();
        Sanctum::actingAs($admin);
        SocialAccount::factory()->connected()->recycle($admin)->create();

        $this->getJson('/api/admin/social/inbox')
            ->assertOk()
            ->assertJsonFragment(['body' => '🤗🤗', 'kind' => 'comment', 'author_name' => 'Guest'])
            ->assertJsonFragment(['source_external_id' => 'post_1', 'source_body' => 'Launch day.']);

        $this->assertSame(1, SocialInboxItem::query()->count());
    }

    public function test_inbox_removes_comments_deleted_on_facebook(): void
    {
        Http::fake([
            'https://graph.facebook.com/*published_posts*' => Http::response([
                'data' => [[
                    'id' => 'post_1',
                    'comments' => ['data' => []],
                ]],
            ], 200),
            'https://graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Unsupported get request. Object with ID does not exist',
                    'code' => 100,
                    'error_subcode' => 33,
                ],
            ], 400),
        ]);

        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $account = SocialAccount::factory()->connected()->recycle($admin)->create();
        $comment = SocialInboxItem::factory()->recycle($account)->create([
            'external_id' => 'cmt_gone',
            'source_external_id' => 'post_1',
            'kind' => SocialInboxKind::Comment,
        ]);

        $this->getJson('/api/admin/social/inbox')->assertOk();

        $this->assertModelMissing($comment);
    }

    public function test_deleting_facebook_post_removes_its_comments(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['success' => true], 200),
        ]);

        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $account = SocialAccount::factory()->connected()->recycle($admin)->create();
        $post = SocialPost::factory()->recycle($admin)->create([
            'created_by' => $admin->id,
            'status' => SocialPostStatus::Published,
            'published_at' => now(),
        ]);
        $post->accounts()->attach($account->id, [
            'status' => SocialPublishStatus::Published->value,
            'external_id' => 'post_live',
        ]);
        $comment = SocialInboxItem::factory()->recycle($account)->create([
            'external_id' => 'post_live_c1',
            'source_external_id' => 'post_live',
            'kind' => SocialInboxKind::Comment,
        ]);

        $this->deleteJson("/api/admin/social/posts/{$post->id}")->assertOk();

        $this->assertModelMissing($post);
        $this->assertModelMissing($comment);
    }

    public function test_staff_can_reply_to_inbox_item(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['id' => 'reply_1'], 200),
        ]);

        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $account = SocialAccount::factory()->connected()->recycle($admin)->create();
        $item = SocialInboxItem::factory()->recycle($account)->create([
            'kind' => SocialInboxKind::Comment,
            'external_id' => 'cmt_1',
        ]);

        $this->postJson("/api/admin/social/inbox/{$item->id}/reply", [
            'body' => 'Thanks for writing.',
        ])->assertOk()
            ->assertJsonPath('data.is_replied', true);

        $this->assertTrue($item->fresh()->is_replied);
        $this->assertSame('Thanks for writing.', $item->replies()->first()?->body);
    }

    public function test_staff_can_reply_to_facebook_message(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['id' => 'mid_1'], 200),
        ]);

        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $account = SocialAccount::factory()->connected()->recycle($admin)->create();
        $item = SocialInboxItem::factory()->message()->recycle($account)->create([
            'external_id' => 'msg_1',
            'author_handle' => 'psid_99',
        ]);

        $this->postJson("/api/admin/social/inbox/{$item->id}/reply", [
            'body' => 'Hello from the page.',
        ])->assertOk()
            ->assertJsonPath('data.is_replied', true);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), $account->page_id.'/messages')
            && str_contains((string) $request['recipient'], 'psid_99')
            && str_contains((string) $request['message'], 'Hello from the page.'));
    }

    public function test_admin_can_publish_facebook_album(): void
    {
        Storage::fake('public');
        Http::fake(function ($request) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/photos')) {
                static $n = 0;
                $n++;

                return Http::response(['id' => 'photo_'.$n], 200);
            }

            return Http::response(['id' => 'album_post'], 200);
        });

        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $account = SocialAccount::factory()->connected()->recycle($admin)->create();

        $this->post('/api/admin/social/posts', [
            'body' => 'Album day.',
            'account_ids' => [$account->id],
            'intent' => 'publish',
            'media' => [
                UploadedFile::fake()->image('one.jpg'),
                UploadedFile::fake()->image('two.jpg'),
            ],
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', SocialPostStatus::Published->value)
            ->assertJsonPath('data.placement', 'feed');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/feed')
            && filled($request['attached_media']));
    }

    public function test_admin_can_publish_instagram_carousel_and_story(): void
    {
        Storage::fake('public');
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'rupload.facebook.com')) {
                return Http::response(['success' => true, 'message' => 'Upload successful.'], 200);
            }
            if (str_contains($url, '/photos')) {
                return Http::response(['id' => 'fb_photo'], 200);
            }
            if (str_contains($url, 'fb_photo')) {
                return Http::response(['images' => [['source' => 'https://cdn.example.test/photo.jpg', 'width' => 1080]]], 200);
            }
            if (str_contains($url, '/media_publish')) {
                return Http::response(['id' => 'ig_live'], 200);
            }
            if (str_contains($url, '/media')) {
                return Http::response(['id' => 'ig_container'], 200);
            }
            if (str_contains($url, 'ig_container')) {
                return Http::response(['status_code' => 'FINISHED'], 200);
            }

            return Http::response(['id' => 'ok'], 200);
        });

        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $account = SocialAccount::factory()->connected()->recycle($admin)->create([
            'platform' => SocialPlatform::Instagram,
            'page_id' => '222',
            'facebook_page_id' => '111',
        ]);

        $this->post('/api/admin/social/posts', [
            'body' => 'Carousel.',
            'account_ids' => [$account->id],
            'intent' => 'publish',
            'media' => [
                UploadedFile::fake()->image('one.jpg'),
                UploadedFile::fake()->image('two.jpg'),
            ],
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', SocialPostStatus::Published->value);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/media')
            && ! str_contains($request->url(), 'media_publish')
            && ($request['media_type'] ?? null) === 'CAROUSEL');

        $this->post('/api/admin/social/posts', [
            'body' => 'Story.',
            'placement' => 'story',
            'account_ids' => [$account->id],
            'intent' => 'publish',
            'media' => [UploadedFile::fake()->image('story.jpg')],
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.placement', 'story')
            ->assertJsonPath('data.status', SocialPostStatus::Published->value);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/media')
            && ! str_contains($request->url(), 'media_publish')
            && ($request['media_type'] ?? null) === 'STORIES');
    }

    public function test_admin_can_publish_facebook_story(): void
    {
        Storage::fake('public');
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/photo_stories')) {
                return Http::response(['success' => true, 'post_id' => 'story_post'], 200);
            }
            if (str_contains($request->url(), '/photos')) {
                return Http::response(['id' => 'photo_1'], 200);
            }

            return Http::response(['id' => 'ok'], 200);
        });

        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $account = SocialAccount::factory()->connected()->recycle($admin)->create();

        $this->post('/api/admin/social/posts', [
            'body' => 'Tonight.',
            'placement' => 'story',
            'account_ids' => [$account->id],
            'intent' => 'publish',
            'media' => [UploadedFile::fake()->image('story.jpg')],
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.placement', 'story')
            ->assertJsonPath('data.status', SocialPostStatus::Published->value);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/photos')
            && collect($request->data())->contains(fn ($part) => ($part['name'] ?? null) === 'published'
                && ($part['contents'] ?? null) === 'false'));
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/photo_stories')
            && ($request['photo_id'] ?? null) === 'photo_1');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/stories')
            && ! str_contains($request->url(), 'photo_stories')
            && ! str_contains($request->url(), 'video_stories'));
    }

    public function test_admin_can_publish_facebook_video_story(): void
    {
        Storage::fake('public');
        $this->fakeFacebookResumableVideo('video_stories', 'story_video_post');

        $admin = $this->admin();
        $account = SocialAccount::factory()->connected()->recycle($admin)->create();

        Storage::disk('public')->put('social/posts/story.mp4', str_repeat('m', 2048));
        $post = SocialPost::factory()->recycle($admin)->create([
            'created_by' => $admin->id,
            'placement' => 'story',
            'body' => 'Tonight clip.',
        ]);
        SocialPostMedia::query()->create([
            'social_post_id' => $post->id,
            'path' => 'social/posts/story.mp4',
            'original_name' => 'story.mp4',
            'mime' => 'video/mp4',
            'kind' => 'video',
            'sort_order' => 1,
        ]);
        $post->accounts()->attach($account->id, ['status' => SocialPublishStatus::Pending->value]);

        $result = app(SocialPublisher::class)->publish($account, $post->fresh(['media']));
        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertSame('story_video_post', $result['external_id']);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/video_stories')
            && ($request['upload_phase'] ?? null) === 'start');
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), 'rupload.facebook.com/video-upload'));
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/video_stories')
            && ($request['upload_phase'] ?? null) === 'finish'
            && ($request['video_id'] ?? null) === 'vid_1');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/videos')
            || (str_contains($request->url(), '/stories')
                && ! str_contains($request->url(), 'video_stories')
                && ! str_contains($request->url(), 'photo_stories')));
    }

    public function test_admin_can_publish_facebook_reel(): void
    {
        Storage::fake('public');
        $this->fakeFacebookResumableVideo('video_reels', 'reel_post');

        $admin = $this->admin();
        $account = SocialAccount::factory()->connected()->recycle($admin)->create();

        Storage::disk('public')->put('social/posts/clip.mp4', str_repeat('m', 2048));
        $post = SocialPost::factory()->recycle($admin)->create([
            'created_by' => $admin->id,
            'placement' => 'reel',
            'body' => 'Reel cut.',
        ]);
        SocialPostMedia::query()->create([
            'social_post_id' => $post->id,
            'path' => 'social/posts/clip.mp4',
            'original_name' => 'clip.mp4',
            'mime' => 'video/mp4',
            'kind' => 'video',
            'sort_order' => 1,
        ]);
        $post->accounts()->attach($account->id, ['status' => SocialPublishStatus::Pending->value]);

        $result = app(SocialPublisher::class)->publish($account, $post->fresh(['media']));
        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertSame('reel_post', $result['external_id']);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/video_reels')
            && ($request['upload_phase'] ?? null) === 'start');
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), 'rupload.facebook.com/video-upload'));
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/video_reels')
            && ($request['upload_phase'] ?? null) === 'finish'
            && ($request['video_id'] ?? null) === 'vid_1'
            && ($request['video_state'] ?? null) === 'PUBLISHED'
            && ($request['description'] ?? null) === 'Reel cut.');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/videos'));
    }

    public function test_admin_can_set_staff_social_permissions(): void
    {
        $admin = $this->admin();
        $editor = $this->admin([SocialAbility::Create->value]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/admin/social/staff/{$editor->id}", [
            'social_permissions' => [SocialAbility::Create->value, SocialAbility::Engage->value],
        ])->assertOk()
            ->assertJsonPath('data.social_abilities', [
                SocialAbility::Create->value,
                SocialAbility::Engage->value,
            ]);
    }

    public function test_public_instagram_feed_returns_all_pages(): void
    {
        Cache::flush();
        config(['services.social.landing_instagram_handle' => 'homeofcreativity.sy']);

        SocialAccount::factory()->connected()->create([
            'platform' => SocialPlatform::Instagram,
            'handle' => 'homeofcreativity.sy',
            'page_id' => '222',
            'name' => 'Pro Design',
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::sequence()
                ->push([
                    'username' => 'homeofcreativity.sy',
                    'name' => 'Pro Design',
                    'biography' => 'Studio',
                    'followers_count' => 1200,
                    'follows_count' => 80,
                    'media_count' => 2,
                ], 200)
                ->push([
                    'data' => [[
                        'id' => '1',
                        'caption' => 'One',
                        'media_type' => 'IMAGE',
                        'media_url' => 'https://cdn.test/1.jpg',
                        'permalink' => 'https://ig/1',
                        'timestamp' => '2026-01-01T00:00:00+0000',
                    ]],
                    'paging' => [
                        'cursors' => ['after' => 'cursor-2'],
                        'next' => 'https://graph.facebook.com/next',
                    ],
                ], 200)
                ->push([
                    'data' => [[
                        'id' => '2',
                        'media_type' => 'VIDEO',
                        'thumbnail_url' => 'https://cdn.test/2.jpg',
                        'permalink' => 'https://ig/2',
                    ]],
                ], 200),
        ]);

        $this->getJson('/api/social/instagram-feed')
            ->assertOk()
            ->assertJsonPath('data.profile.username', 'homeofcreativity.sy')
            ->assertJsonPath('data.profile.permalink', 'https://www.instagram.com/homeofcreativity.sy/')
            ->assertJsonCount(2, 'data.posts')
            ->assertJsonPath('data.posts.0.id', '1')
            ->assertJsonPath('data.posts.1.preview_url', 'https://cdn.test/2.jpg');
    }

    public function test_public_facebook_feed_returns_page_posts(): void
    {
        Cache::flush();

        SocialAccount::factory()->connected()->create([
            'platform' => SocialPlatform::Facebook,
            'handle' => 'homeofcreativity',
            'page_id' => '555',
            'name' => 'Home Of Creativity',
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::sequence()
                ->push([
                    'name' => 'Home Of Creativity',
                    'username' => 'homeofcreativity',
                    'about' => 'Studio',
                    'fan_count' => 99,
                    'followers_count' => 120,
                    'link' => 'https://facebook.com/homeofcreativity',
                    'picture' => ['data' => ['url' => 'https://cdn.test/page.jpg']],
                ], 200)
                ->push([
                    'data' => [[
                        'id' => 'fb1',
                        'message' => 'Hello',
                        'full_picture' => 'https://cdn.test/fb1.jpg',
                        'permalink_url' => 'https://facebook.com/fb1',
                        'created_time' => '2026-01-01T00:00:00+0000',
                        'attachments' => [
                            'data' => [[
                                'media_type' => 'photo',
                                'media' => ['image' => ['src' => 'https://cdn.test/fb1.jpg']],
                            ]],
                        ],
                    ], [
                        'id' => 'fb2',
                        'status_type' => 'added_video',
                        'full_picture' => 'https://cdn.test/fb2.jpg',
                        'permalink_url' => 'https://facebook.com/fb2',
                        'attachments' => [
                            'data' => [[
                                'media_type' => 'video',
                                'media' => [
                                    'source' => 'https://cdn.test/fb2.mp4',
                                    'image' => ['src' => 'https://cdn.test/fb2.jpg'],
                                ],
                            ]],
                        ],
                    ]],
                ], 200),
        ]);

        $this->getJson('/api/social/facebook-feed')
            ->assertOk()
            ->assertJsonPath('data.profile.username', 'homeofcreativity')
            ->assertJsonPath('data.profile.followers_count', 120)
            ->assertJsonCount(2, 'data.posts')
            ->assertJsonPath('data.posts.0.media_type', 'IMAGE')
            ->assertJsonPath('data.posts.1.media_type', 'VIDEO')
            ->assertJsonPath('data.posts.1.media_url', 'https://cdn.test/fb2.mp4');
    }

    public function test_public_facebook_feed_uses_page_linked_to_landing_instagram(): void
    {
        Cache::flush();
        config(['services.social.landing_instagram_handle' => 'homeofcreativity.sy']);

        SocialAccount::factory()->connected()->create([
            'platform' => SocialPlatform::Facebook,
            'name' => 'Pro Design',
            'handle' => 'Pro Design',
            'page_id' => '921',
        ]);
        SocialAccount::factory()->connected()->create([
            'platform' => SocialPlatform::Facebook,
            'name' => 'Home Of Creativity',
            'handle' => 'homeofcreativity',
            'page_id' => '555',
        ]);
        SocialAccount::factory()->connected()->create([
            'platform' => SocialPlatform::Instagram,
            'handle' => 'homeofcreativity.sy',
            'page_id' => '222',
            'facebook_page_id' => '555',
            'name' => 'Home Of Creativity',
        ]);

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, '/555/published_posts') || str_contains($url, '/555/posts')) {
                return Http::response([
                    'data' => [[
                        'id' => 'fb-hoc',
                        'message' => 'HOC',
                        'full_picture' => 'https://cdn.test/hoc.jpg',
                        'permalink_url' => 'https://facebook.com/hoc',
                        'created_time' => '2026-01-01T00:00:00+0000',
                    ]],
                ]);
            }
            if (str_contains($url, '/555')) {
                return Http::response([
                    'name' => 'Home Of Creativity',
                    'username' => 'homeofcreativity',
                    'followers_count' => 120,
                    'fan_count' => 99,
                    'link' => 'https://facebook.com/homeofcreativity',
                ]);
            }

            return Http::response(['error' => ['message' => 'wrong page']], 400);
        });

        $this->getJson('/api/social/facebook-feed')
            ->assertOk()
            ->assertJsonPath('data.profile.username', 'homeofcreativity')
            ->assertJsonPath('data.posts.0.id', 'fb-hoc');
    }

    public function test_instagram_publish_repairs_user_id_when_page_id_is_the_facebook_page(): void
    {
        Storage::fake('public');
        Http::fake(function (Request $request) {
            $url = $request->url();
            if ($request->method() === 'GET' && str_contains($url, '/111') && ! str_contains($url, '/111/')) {
                return Http::response(['instagram_business_account' => ['id' => '222']], 200);
            }
            if (str_contains($url, '/photos')) {
                return Http::response(['id' => 'fb_photo'], 200);
            }
            if (str_contains($url, 'fb_photo')) {
                return Http::response(['images' => [['source' => 'https://cdn.example.test/photo.jpg', 'width' => 1080]]], 200);
            }
            if (str_contains($url, '/media_publish')) {
                return Http::response(['id' => 'ig_88'], 200);
            }
            if (str_contains($url, '/222/media')) {
                return Http::response(['id' => 'ig_container'], 200);
            }
            if (str_contains($url, 'ig_container')) {
                return Http::response(['status_code' => 'FINISHED'], 200);
            }

            return Http::response([
                'error' => [
                    'message' => '(#200) New Pages Experience Is Not Supported: This endpoint is not supported in the new Pages experience.',
                    'code' => 200,
                ],
            ], 400);
        });

        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $account = SocialAccount::factory()->connected()->recycle($admin)->create([
            'platform' => SocialPlatform::Instagram,
            'name' => 'Damastech.ae',
            'page_id' => '111',
            'facebook_page_id' => '111',
        ]);

        $this->post('/api/admin/social/posts', [
            'body' => 'اهلا ومرحبا',
            'account_ids' => [$account->id],
            'intent' => 'publish',
            'media' => [UploadedFile::fake()->image('hero.jpg')],
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', SocialPostStatus::Published->value)
            ->assertJsonPath('data.last_error', null);

        $this->assertSame('222', $account->fresh()->page_id);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), '/222/media')
            && ! str_contains($request->url(), 'media_publish'));
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), '/111/media'));
    }

    public function test_instagram_publish_uses_resumable_upload_when_page_photos_hit_new_pages_experience(): void
    {
        Storage::fake('public');
        Http::fake(function (Request $request) {
            $url = $request->url();
            if ($request->method() === 'POST' && str_contains($url, '/photos')) {
                return Http::response([
                    'error' => [
                        'message' => '(#200) New Pages Experience Is Not Supported: This endpoint is not supported in the new Pages experience.',
                        'code' => 200,
                    ],
                ], 400);
            }
            if (str_contains($url, 'rupload.facebook.com')) {
                return Http::response(['success' => true, 'message' => 'Upload successful.'], 200);
            }
            if (str_contains($url, '/media_publish')) {
                return Http::response(['id' => 'ig_88'], 200);
            }
            if (str_contains($url, '/media')) {
                return Http::response(['id' => 'ig_container'], 200);
            }
            if (str_contains($url, 'ig_container')) {
                return Http::response(['status_code' => 'FINISHED'], 200);
            }

            return Http::response(['id' => 'ok'], 200);
        });

        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $account = SocialAccount::factory()->connected()->recycle($admin)->create([
            'platform' => SocialPlatform::Instagram,
            'name' => 'Damastech.ae',
            'page_id' => '222',
            'facebook_page_id' => '111',
        ]);

        $this->post('/api/admin/social/posts', [
            'body' => 'اهلا ومرحبا',
            'account_ids' => [$account->id],
            'intent' => 'publish',
            'media' => [UploadedFile::fake()->image('hero.jpg')],
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', SocialPostStatus::Published->value);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'rupload.facebook.com'));
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), '/media')
            && ($request['upload_type'] ?? null) === 'resumable');
    }

    public function test_inbox_sync_skips_new_pages_experience_edges_and_imports_photos(): void
    {
        $npe = [
            'error' => [
                'message' => '(#200) New Pages Experience Is Not Supported: This endpoint is not supported in the new Pages experience.',
                'code' => 200,
            ],
        ];

        Http::fake(function (Request $request) use ($npe) {
            $url = $request->url();
            if (str_contains($url, 'published_posts') || str_contains($url, '/feed') || str_contains($url, '/conversations')) {
                return Http::response($npe, 400);
            }
            if (str_contains($url, '/photos')) {
                return Http::response([
                    'data' => [[
                        'id' => 'photo_1',
                        'name' => 'Hall shot.',
                        'link' => 'https://facebook.com/photo_1',
                        'images' => [['source' => 'https://cdn.test/photo.jpg', 'width' => 1080]],
                        'comments' => [
                            'data' => [[
                                'id' => 'cmt_npe',
                                'message' => 'Nice',
                                'from' => ['id' => 'u1', 'name' => 'Guest'],
                                'created_time' => now()->toIso8601String(),
                            ]],
                        ],
                    ]],
                ], 200);
            }

            return Http::response(['data' => []], 200);
        });

        $admin = $this->admin();
        Sanctum::actingAs($admin);
        SocialAccount::factory()->connected()->recycle($admin)->create([
            'name' => 'أبو شاكر',
            'page_id' => '111',
        ]);

        $this->getJson('/api/admin/social/inbox')
            ->assertOk()
            ->assertJsonPath('sync_error', null)
            ->assertJsonFragment(['body' => 'Nice', 'kind' => 'comment', 'author_name' => 'Guest']);
    }

    public function test_public_facebook_feed_falls_back_to_photos_on_new_pages_experience(): void
    {
        Cache::flush();

        SocialAccount::factory()->connected()->create([
            'platform' => SocialPlatform::Facebook,
            'handle' => 'homeofcreativity',
            'page_id' => '555',
            'name' => 'Home Of Creativity',
        ]);

        $npe = [
            'error' => [
                'message' => '(#200) New Pages Experience Is Not Supported: This endpoint is not supported in the new Pages experience.',
                'code' => 200,
            ],
        ];

        Http::fake(function (Request $request) use ($npe) {
            $url = $request->url();
            if (str_contains($url, 'published_posts') || str_contains($url, '/555/posts')) {
                return Http::response($npe, 400);
            }
            if (str_contains($url, '/555/photos')) {
                return Http::response([
                    'data' => [[
                        'id' => 'photo_npe',
                        'name' => 'Studio',
                        'link' => 'https://facebook.com/photo_npe',
                        'created_time' => '2026-01-01T00:00:00+0000',
                        'images' => [['source' => 'https://cdn.test/npe.jpg', 'width' => 1200]],
                    ]],
                ], 200);
            }
            if (str_contains($url, '/555/videos')) {
                return Http::response(['data' => []], 200);
            }
            if (str_contains($url, '/555')) {
                return Http::response([
                    'name' => 'Home Of Creativity',
                    'username' => 'homeofcreativity',
                    'followers_count' => 120,
                    'fan_count' => 99,
                    'link' => 'https://facebook.com/homeofcreativity',
                ], 200);
            }

            return Http::response($npe, 400);
        });

        $this->getJson('/api/social/facebook-feed')
            ->assertOk()
            ->assertJsonPath('data.profile.username', 'homeofcreativity')
            ->assertJsonCount(1, 'data.posts')
            ->assertJsonPath('data.posts.0.id', 'photo_npe')
            ->assertJsonPath('data.posts.0.media_url', 'https://cdn.test/npe.jpg');
    }

    public function test_facebook_text_post_fails_clearly_on_new_pages_experience(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => '(#200) New Pages Experience Is Not Supported: This endpoint is not supported in the new Pages experience.',
                    'code' => 200,
                ],
            ], 400),
        ]);

        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $account = SocialAccount::factory()->connected()->recycle($admin)->create();

        $this->postJson('/api/admin/social/posts', [
            'body' => 'Text only.',
            'account_ids' => [$account->id],
            'intent' => 'publish',
        ])->assertCreated()
            ->assertJsonPath('data.status', SocialPostStatus::Failed->value)
            ->assertJsonPath('data.last_error', $account->name.': facebook_new_pages_text');
    }

    public function test_facebook_album_falls_back_to_photo_when_feed_is_new_pages_experience(): void
    {
        Storage::fake('public');
        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/feed')) {
                return Http::response([
                    'error' => [
                        'message' => '(#200) New Pages Experience Is Not Supported: This endpoint is not supported in the new Pages experience.',
                        'code' => 200,
                    ],
                ], 400);
            }
            if ($request->method() === 'POST' && str_contains($request->url(), '/photos')) {
                return Http::response(['id' => 'photo_live', 'post_id' => 'page_photo_live'], 200);
            }

            return Http::response(['id' => 'ok'], 200);
        });

        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $account = SocialAccount::factory()->connected()->recycle($admin)->create();

        $this->post('/api/admin/social/posts', [
            'body' => 'Album day.',
            'account_ids' => [$account->id],
            'intent' => 'publish',
            'media' => [
                UploadedFile::fake()->image('one.jpg'),
                UploadedFile::fake()->image('two.jpg'),
            ],
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', SocialPostStatus::Published->value);
    }

    /**
     * @param  list<string>|null  $permissions
     */
    private function admin(?array $permissions = null): User
    {
        return User::factory()->admin()->create([
            'social_permissions' => $permissions,
        ]);
    }

    private function fakeFacebookResumableVideo(string $edge, string $postId): void
    {
        Http::fake(function ($request) use ($edge, $postId) {
            $url = $request->url();
            if (str_contains($url, 'rupload.facebook.com')) {
                return Http::response(['success' => true], 200);
            }
            if (str_contains($url, '/'.$edge)) {
                if (($request['upload_phase'] ?? '') === 'start') {
                    return Http::response([
                        'video_id' => 'vid_1',
                        'upload_url' => 'https://rupload.facebook.com/video-upload/v21.0/vid_1',
                    ], 200);
                }

                return Http::response(['success' => true, 'post_id' => $postId], 200);
            }

            return Http::response(['id' => 'ok'], 200);
        });
    }
}
