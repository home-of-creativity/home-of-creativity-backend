<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ReportCalendar;
use App\Models\ReportDeadline;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportCalendarsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->client = Client::factory()->create();
        Sanctum::actingAs($this->admin);
    }

    private function role(string $name, array $abilities): Role
    {
        return Role::query()->create(['name' => $name, 'abilities' => $abilities]);
    }

    private function staff(Role $role): User
    {
        return User::factory()->create(['is_admin' => false, 'role_id' => $role->id]);
    }

    private function range(): string
    {
        return '/api/admin/report-calendar?from=2026-10-01&to=2026-11-01';
    }

    private function deadline(?int $calendarId, string $title): ReportDeadline
    {
        return ReportDeadline::query()->create([
            'client_id' => $this->client->id,
            'report_calendar_id' => $calendarId,
            'title' => $title,
            'starts_on' => '2026-10-15',
            'repeat' => 'none',
        ]);
    }

    public function test_a_new_calendar_becomes_a_view_permission_for_the_roles_picked(): void
    {
        $design = $this->role('التصميم', ['ops.reports.view']);
        $sales = $this->role('المبيعات', ['ops.reports.view']);

        $id = $this->postJson('/api/admin/report-calendars', [
            'name' => 'تقارير السوشال',
            'color' => '#33b679',
            'role_ids' => [$design->id],
        ])->assertCreated()
            ->assertJsonPath('data.ability', 'ops.report_calendar.1')
            ->assertJsonPath('data.roles', ['التصميم'])
            ->json('data.id');

        $this->assertContains("ops.report_calendar.{$id}", $design->fresh()->abilities);
        $this->assertNotContains("ops.report_calendar.{$id}", $sales->fresh()->abilities);

        $calendar = collect($this->getJson('/api/admin/staff-access')->assertOk()->json('abilities'))
            ->firstWhere('key', "ops.report_calendar.{$id}");
        $this->assertSame('calendars', $calendar['group']);
        $this->assertSame('تقارير السوشال', $calendar['label_ar']);
        $this->assertSame(['التصميم'], $calendar['viewers']);
    }

    public function test_staff_see_only_the_calendars_their_role_holds(): void
    {
        $calendar = ReportCalendar::query()->create(['name' => 'خاص']);
        $this->deadline(null, 'رئيسي');
        $this->deadline($calendar->id, 'سري');

        $without = $this->staff($this->role('بلا تقويم', ['ops.reports.view', 'ops.reports.create', 'ops.reports.delete', 'ops.report_calendar.main']));
        Sanctum::actingAs($without);
        $response = $this->getJson($this->range())->assertOk();
        $this->assertSame(['رئيسي'], array_column($response->json('data.deadlines'), 'title'));
        $this->assertSame([], $response->json('data.calendars'));
        $this->assertFalse($response->json('data.can_manage_calendars'));

        $this->postJson('/api/admin/report-deadlines', [
            'client_id' => $this->client->id,
            'report_calendar_id' => $calendar->id,
            'title' => 'محاولة',
            'starts_on' => '2026-10-20',
            'repeat' => 'none',
        ])->assertForbidden();

        $hidden = ReportDeadline::query()->where('title', 'سري')->firstOrFail();
        $this->deleteJson("/api/admin/report-deadlines/{$hidden->id}")->assertNotFound();

        $with = $this->staff($this->role('مع التقويم', ['ops.reports.view', 'ops.reports.create', 'ops.report_calendar.main', $calendar->abilityKey()]));
        Sanctum::actingAs($with);
        $titles = array_column($this->getJson($this->range())->json('data.deadlines'), 'title');
        sort($titles);
        $this->assertSame(['رئيسي', 'سري'], $titles);

        $this->postJson('/api/admin/report-deadlines', [
            'client_id' => $this->client->id,
            'report_calendar_id' => $calendar->id,
            'title' => 'موعد جديد',
            'starts_on' => '2026-10-20',
            'repeat' => 'none',
        ])->assertCreated();
    }

    public function test_the_main_calendar_needs_its_own_permission(): void
    {
        $calendar = ReportCalendar::query()->create(['name' => 'خاص']);
        $main = $this->deadline(null, 'رئيسي');
        $this->deadline($calendar->id, 'سري');

        Sanctum::actingAs($this->staff($this->role('تقويم خاص فقط', ['ops.reports.view', 'ops.reports.create', 'ops.reports.update', 'ops.reports.delete', $calendar->abilityKey()])));
        $response = $this->getJson($this->range())->assertOk();
        $this->assertSame(['سري'], array_column($response->json('data.deadlines'), 'title'));
        $this->assertFalse($response->json('data.main_visible'));

        $this->deleteJson("/api/admin/report-deadlines/{$main->id}")->assertNotFound();
        $this->putJson("/api/admin/report-deadlines/{$main->id}/occurrences/2026-10-15", ['action' => 'done'])->assertNotFound();
        $this->postJson('/api/admin/report-deadlines', [
            'client_id' => $this->client->id,
            'title' => 'على الرئيسي',
            'starts_on' => '2026-10-20',
            'repeat' => 'none',
        ])->assertForbidden();

        Sanctum::actingAs($this->admin);
        $row = collect($this->getJson('/api/admin/staff-access')->assertOk()->json('abilities'))
            ->firstWhere('key', 'ops.report_calendar.main');
        $this->assertSame('calendars', $row['group']);
        $this->assertSame([], $row['viewers']);
    }

    public function test_an_unrestricted_admin_sees_every_calendar_and_can_manage(): void
    {
        $calendar = ReportCalendar::query()->create(['name' => 'المالية']);
        $this->deadline($calendar->id, 'تقرير مالي');

        $this->getJson($this->range())
            ->assertOk()
            ->assertJsonPath('data.can_manage_calendars', true)
            ->assertJsonPath('data.calendars.0.name', 'المالية')
            ->assertJsonPath('data.deadlines.0.calendar_id', $calendar->id);
    }

    public function test_a_role_can_be_saved_with_a_calendar_permission(): void
    {
        $calendar = ReportCalendar::query()->create(['name' => 'المحتوى']);

        $this->postJson('/api/admin/roles', [
            'name' => 'كتّاب',
            'abilities' => ['ops.reports.view', $calendar->abilityKey()],
        ])->assertCreated();

        $this->postJson('/api/admin/roles', [
            'name' => 'مزيف',
            'abilities' => ['ops.reports.view', 'ops.report_calendar.999'],
        ])->assertStatus(422);
    }

    public function test_the_creator_keeps_seeing_a_calendar_and_others_need_the_manage_permission(): void
    {
        $manager = $this->role('مدير التقارير', ['ops.reports.view', 'ops.report_calendars']);
        $user = $this->staff($manager);
        Sanctum::actingAs($user);

        $id = $this->postJson('/api/admin/report-calendars', ['name' => 'فريقي'])->assertCreated()->json('data.id');
        $this->assertContains("ops.report_calendar.{$id}", $manager->fresh()->abilities);
        // A new request loads the role again, as production does.
        Sanctum::actingAs($user->fresh());
        $this->getJson($this->range())->assertJsonPath('data.calendars.0.id', $id);

        Sanctum::actingAs($this->staff($this->role('موظف', ['ops.reports.view'])));
        $this->postJson('/api/admin/report-calendars', ['name' => 'ممنوع'])->assertForbidden();
        $this->putJson("/api/admin/report-calendars/{$id}", ['name' => 'تغيير'])->assertForbidden();
    }

    public function test_deleting_a_calendar_removes_its_deadlines_and_its_permission(): void
    {
        $calendar = ReportCalendar::query()->create(['name' => 'مؤقت']);
        $role = $this->role('فريق', ['ops.reports.view', $calendar->abilityKey()]);
        $this->deadline($calendar->id, 'يُحذف');
        $this->deadline(null, 'يبقى');

        $this->deleteJson("/api/admin/report-calendars/{$calendar->id}")->assertOk();

        $this->assertSame(['يبقى'], ReportDeadline::query()->pluck('title')->all());
        $this->assertSame(['ops.reports.view'], $role->fresh()->abilities);
    }

    public function test_renaming_moves_the_permission_between_roles(): void
    {
        $calendar = ReportCalendar::query()->create(['name' => 'قديم']);
        $first = $this->role('أ', ['ops.reports.view', $calendar->abilityKey()]);
        $second = $this->role('ب', ['ops.reports.view']);

        $this->putJson("/api/admin/report-calendars/{$calendar->id}", [
            'name' => 'جديد',
            'color' => '#7986cb',
            'role_ids' => [$second->id],
        ])->assertOk()->assertJsonPath('data.roles', ['ب']);

        $this->assertNotContains($calendar->abilityKey(), $first->fresh()->abilities);
        $this->assertContains($calendar->abilityKey(), $second->fresh()->abilities);
        $this->assertSame('جديد', $calendar->fresh()->name);
    }
}
