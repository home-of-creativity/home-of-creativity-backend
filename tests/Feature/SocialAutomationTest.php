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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
            ->assertJsonFragment(['platform' => 'facebook', 'page_id' => '111', 'name' => 'Home of Creativity'])
            ->assertJsonFragment(['platform' => 'instagram', 'handle' => 'homeofcreativity'])
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
            \Carbon\Carbon::parse($scheduled)->equalTo(\Carbon\Carbon::parse('2026-09-12 13:28:00', 'UTC'))
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

    public function test_inbox_sync_imports_published_post_comments(): void
    {
        Http::fake([
            'https://graph.facebook.com/*published_posts*' => Http::response([
                'data' => [[
                    'id' => 'post_1',
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
            ->assertJsonFragment(['body' => '🤗🤗', 'kind' => 'comment', 'author_name' => 'Guest']);

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

    /**
     * @param  list<string>|null  $permissions
     */
    private function admin(?array $permissions = null): User
    {
        return User::factory()->admin()->create([
            'social_permissions' => $permissions,
        ]);
    }
}
