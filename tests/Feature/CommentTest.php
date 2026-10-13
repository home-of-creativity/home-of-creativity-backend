<?php

namespace Tests\Feature;

use App\Enums\StaffAbility;
use App\Models\Client;
use App\Models\ClientReport;
use App\Models\Comment;
use App\Models\PhotographyCalendarRole;
use App\Models\Role;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Team comments follow the permissions of the thing they sit on.
 */
class CommentTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_team_comments_replies_edits_and_deletes_on_a_report(): void
    {
        $report = $this->report();
        $writer = $this->staff([StaffAbility::OpsReports->value.'.view']);
        Sanctum::actingAs($writer);

        $this->getJson("/api/admin/comments?subject=report&id={$report->id}")->assertOk()->assertJsonCount(0, 'data');
        $first = $this->postJson('/api/admin/comments', ['subject' => 'report', 'id' => (string) $report->id, 'body' => 'راجعوا الصفحة الثانية'])
            ->assertCreated()
            ->assertJsonPath('data.user.name', $writer->name)
            ->assertJsonPath('data.can_edit', true)
            ->json('data');

        // Another reader replies; a reply to that reply stays in the same thread.
        $reader = $this->staff([StaffAbility::OpsReports->value]);
        Sanctum::actingAs($reader);
        $reply = $this->postJson('/api/admin/comments', ['subject' => 'report', 'id' => (string) $report->id, 'body' => 'تم', 'parent_id' => $first['id']])->assertCreated()->json('data');
        $this->postJson('/api/admin/comments', ['subject' => 'report', 'id' => (string) $report->id, 'body' => 'وكمان الغلاف', 'parent_id' => $reply['id']])
            ->assertCreated()->assertJsonPath('data.parent_id', $first['id']);
        $this->getJson("/api/admin/comments?subject=report&id={$report->id}")->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.can_edit', false)->assertJsonPath('data.0.can_delete', false);

        // Only the author edits or deletes their comment.
        $this->patchJson("/api/admin/comments/{$first['id']}", ['body' => 'x'])->assertForbidden();
        $this->deleteJson("/api/admin/comments/{$first['id']}")->assertForbidden();
        Sanctum::actingAs($writer);
        $this->patchJson("/api/admin/comments/{$first['id']}", ['body' => 'راجعوا الصفحة الثالثة'])->assertOk()->assertJsonPath('data.body', 'راجعوا الصفحة الثالثة');
        $this->assertNotNull(Comment::query()->find($first['id'])->edited_at);
        $this->getJson("/api/admin/comments/counts?subject=report&ids[]={$report->id}")->assertOk()->assertJsonPath("data.{$report->id}", 3);

        // Deleting the thread start removes its replies.
        $this->deleteJson("/api/admin/comments/{$first['id']}")->assertOk();
        $this->assertSame(0, Comment::query()->count());
    }

    public function test_comments_follow_the_permissions_of_each_feature(): void
    {
        $report = $this->report();
        $post = SocialPost::factory()->create();
        $social = $this->staff([StaffAbility::SocialContent->value]);
        Sanctum::actingAs($social);

        $this->postJson('/api/admin/comments', ['subject' => 'social_post', 'id' => (string) $post->id, 'body' => 'غيّروا الصورة'])->assertCreated();
        $this->getJson("/api/admin/comments?subject=report&id={$report->id}")->assertForbidden();
        $this->postJson('/api/admin/comments', ['subject' => 'report', 'id' => (string) $report->id, 'body' => 'x'])->assertForbidden();
        $this->postJson('/api/admin/comments', ['subject' => 'photography_booking', 'id' => '1', 'body' => 'x'])->assertForbidden();

        // A comment on a post this user cannot see is out of reach as well.
        $comment = Comment::query()->sole();
        Sanctum::actingAs($this->staff([StaffAbility::OpsReports->value]));
        $this->patchJson("/api/admin/comments/{$comment->id}", ['body' => 'x'])->assertForbidden();
        $this->getJson("/api/admin/comments?subject=social_post&id={$post->id}")->assertForbidden();

        // A missing report is refused, not opened.
        $this->getJson('/api/admin/comments?subject=report&id=999')->assertForbidden();
    }

    public function test_calendar_event_comments_follow_the_calendar_access(): void
    {
        $team = Role::query()->create(['name' => 'تصوير', 'abilities' => [StaffAbility::OpsPhotography->value]]);
        $other = Role::query()->create(['name' => 'مونتاج', 'abilities' => [StaffAbility::OpsPhotography->value]]);
        PhotographyCalendarRole::query()->create(['calendar_id' => 'shoots@group', 'role_id' => $team->id, 'can_edit' => false]);
        $event = ['subject' => 'calendar_event', 'id' => 'evt_20261013', 'calendar_id' => 'shoots@group'];

        Sanctum::actingAs(User::factory()->create(['role_id' => $team->id]));
        $this->postJson('/api/admin/comments', $event + ['body' => 'جيبوا الإضاءة'])->assertCreated();
        $this->getJson('/api/admin/comments?'.http_build_query($event))->assertOk()->assertJsonCount(1, 'data');
        // The same event id on another calendar is another thread.
        $this->getJson('/api/admin/comments?'.http_build_query(['calendar_id' => 'team@group'] + $event))->assertOk()->assertJsonCount(0, 'data');

        // A role the restricted calendar does not list cannot read it.
        Sanctum::actingAs(User::factory()->create(['role_id' => $other->id]));
        $this->getJson('/api/admin/comments?'.http_build_query($event))->assertForbidden();
        $this->postJson('/api/admin/comments', ['subject' => 'calendar_event', 'id' => 'evt_20261013', 'body' => 'x'])->assertUnprocessable();

        // The full admin reads it and may remove anyone's comment.
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);
        $row = $this->getJson('/api/admin/comments?'.http_build_query($event))->assertOk()->json('data.0');
        $this->assertTrue($row['can_delete']);
        $this->deleteJson("/api/admin/comments/{$row['id']}")->assertOk();
    }

    /** @param  list<string>  $abilities */
    private function staff(array $abilities): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->create(['name' => 'دور '.uniqid(), 'abilities' => $abilities])->id,
        ]);
    }

    private function report(): ClientReport
    {
        return ClientReport::query()->create([
            'client_id' => Client::factory()->create()->id,
            'title' => 'تقرير أكتوبر',
            'body' => '',
        ]);
    }
}
