<?php

namespace Tests\Feature;

use App\Enums\SocialInboxKind;
use App\Enums\StaffAbility;
use App\Models\Employee;
use App\Models\Role;
use App\Models\SocialAccount;
use App\Models\SocialInboxItem;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_a_named_role_and_assigns_pages_to_an_employee(): void
    {
        Http::fake();
        $admin = User::factory()->admin()->create();
        $employee = Employee::factory()->create(['email' => 'editor@hoc.test']);
        $page = SocialAccount::factory()->connected()->create(['page_id' => '111', 'name' => 'Page A']);
        $other = SocialAccount::factory()->connected()->create(['page_id' => '222', 'name' => 'Page B']);
        SocialInboxItem::factory()->create(['social_account_id' => $page->id, 'kind' => SocialInboxKind::Comment]);
        $message = SocialInboxItem::factory()->message()->create(['social_account_id' => $page->id]);
        SocialInboxItem::factory()->create(['social_account_id' => $other->id, 'kind' => SocialInboxKind::Comment]);
        $hiddenPost = SocialPost::factory()->create();
        $hiddenPost->accounts()->attach($other->id);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/roles', [
            'name' => 'محرر الصفحة',
            'abilities' => [StaffAbility::SocialContent->value, StaffAbility::SocialEngage->value],
        ])->assertSuccessful()
            ->assertJsonPath('data.name', 'محرر الصفحة');

        $role = Role::query()->firstOrFail();

        $this->putJson("/api/admin/staff-access/{$employee->id}", [
            'role_id' => $role->id,
            'page_keys' => ['111'],
            'password' => 'secret-pass',
        ])->assertOk()
            ->assertJsonPath('data.role_name', 'محرر الصفحة')
            ->assertJsonPath('data.page_keys.0', '111');

        $this->deleteJson("/api/admin/roles/{$role->id}")->assertStatus(422);

        $this->postJson('/api/auth/login', [
            'email' => 'editor@hoc.test',
            'password' => 'secret-pass',
        ])->assertOk()
            ->assertJsonPath('data.user.role.name', 'محرر الصفحة');

        $editor = User::query()->where('email', 'editor@hoc.test')->firstOrFail();
        Sanctum::actingAs($editor);

        $this->getJson('/api/admin/requests')->assertForbidden();

        $this->getJson('/api/admin/social/accounts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Page A');

        $this->getJson('/api/admin/social/posts')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/admin/social/inbox')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kind', SocialInboxKind::Comment->value);

        $this->postJson("/api/admin/social/inbox/{$message->id}/reply", [
            'body' => 'On it.',
        ])->assertForbidden();
    }

    public function test_messages_permission_can_see_messages_but_not_comments(): void
    {
        Http::fake();
        $page = SocialAccount::factory()->connected()->create(['page_id' => '111']);
        SocialInboxItem::factory()->create(['social_account_id' => $page->id]);
        SocialInboxItem::factory()->message()->create(['social_account_id' => $page->id]);

        $role = Role::query()->create([
            'name' => 'الرسائل',
            'abilities' => [StaffAbility::SocialMessages->value],
        ]);
        $user = User::factory()->create(['role_id' => $role->id]);
        $user->pageGrants()->create(['page_key' => '111', 'ability' => StaffAbility::SocialMessages->value]);
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/social/inbox')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kind', SocialInboxKind::Message->value);
    }

    public function test_social_page_grants_follow_each_ability(): void
    {
        Http::fake();
        $contentPage = SocialAccount::factory()->connected()->create(['page_id' => '111', 'name' => 'Content']);
        $messagePage = SocialAccount::factory()->connected()->create(['page_id' => '222', 'name' => 'Inbox']);
        SocialInboxItem::factory()->create(['social_account_id' => $contentPage->id, 'kind' => SocialInboxKind::Comment]);
        SocialInboxItem::factory()->message()->create(['social_account_id' => $messagePage->id]);
        SocialInboxItem::factory()->message()->create(['social_account_id' => $contentPage->id]);

        $role = Role::query()->create([
            'name' => 'محتوى ورسائل',
            'abilities' => [StaffAbility::SocialContent->value, StaffAbility::SocialEngage->value, StaffAbility::SocialMessages->value],
        ]);
        $user = User::factory()->create(['role_id' => $role->id]);
        $user->pageGrants()->create(['ability' => StaffAbility::SocialContent->value, 'page_key' => '111']);
        $user->pageGrants()->create(['ability' => StaffAbility::SocialEngage->value, 'page_key' => '111']);
        $user->pageGrants()->create(['ability' => StaffAbility::SocialMessages->value, 'page_key' => '222']);
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/social/accounts')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $inbox = $this->getJson('/api/admin/social/inbox')->assertOk();
        $this->assertSame(
            [SocialInboxKind::Comment->value],
            collect($inbox->json('data'))->where('account.id', $contentPage->id)->pluck('kind')->all(),
        );
        $this->assertSame(
            [SocialInboxKind::Message->value],
            collect($inbox->json('data'))->where('account.id', $messagePage->id)->pluck('kind')->all(),
        );
    }

    public function test_table_view_does_not_allow_updates(): void
    {
        $role = Role::query()->create([
            'name' => 'قارئ التصنيفات',
            'abilities' => ['site.categories.view'],
        ]);
        $user = User::factory()->create(['role_id' => $role->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/portfolio/categories')->assertOk();
        $this->postJson('/api/admin/portfolio/categories', [])->assertForbidden();
    }

    public function test_legacy_section_ability_expands_when_the_role_is_saved(): void
    {
        $admin = User::factory()->admin()->create();
        $role = Role::query()->create([
            'name' => 'عملاء',
            'abilities' => ['ops.clients', 'ops.payments'],
        ]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/admin/roles/{$role->id}", [
            'name' => 'عملاء',
            'abilities' => ['ops.clients', 'ops.payments'],
        ])->assertOk();

        $role->refresh();
        $this->assertContains('ops.clients.view', $role->abilities);
        $this->assertContains('ops.clients.delete', $role->abilities);
        $this->assertNotContains('ops.clients', $role->abilities);
        $this->assertContains('ops.payments', $role->abilities);
    }
}
