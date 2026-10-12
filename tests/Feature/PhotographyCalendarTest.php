<?php

namespace Tests\Feature;

use App\Enums\StaffAbility;
use App\Models\PhotographyBooking;
use App\Models\Role;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\GoogleServiceAccount;
use App\Support\RecurrenceRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhotographyCalendarTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://www.googleapis.com/calendar/v3';

    private const MAIN = 'shoots@group.calendar.google.com';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.calendar_id' => self::MAIN]);
        $this->mock(GoogleServiceAccount::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('accessToken')->andReturn('token');
            $mock->shouldReceive('clientEmail')->andReturn('hoc@project.iam.gserviceaccount.com');
        });
        Carbon::setTestNow(Carbon::parse('2026-10-10 10:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_tab_lists_calendars_and_events_and_hides_shoot_events(): void
    {
        Http::fake([
            self::API.'/users/me/calendarList*' => Http::response(['items' => [
                ['id' => 'team@group.calendar.google.com', 'summary' => 'الفريق', 'accessRole' => 'reader', 'backgroundColor' => '#9fe1e7'],
                ['id' => self::MAIN, 'summary' => 'التصوير', 'accessRole' => 'writer'],
            ]]),
            self::API.'/calendars/'.rawurlencode(self::MAIN).'/events*' => Http::response(['items' => [
                $this->item('meeting', '2026-10-12T10:00:00+03:00', '2026-10-12T11:00:00+03:00', ['summary' => 'اجتماع']),
                $this->item('shoot', '2026-10-12T13:00:00+03:00', '2026-10-12T16:00:00+03:00', [
                    'extendedProperties' => ['private' => ['hoc_booking' => '5']],
                ]),
            ]]),
            self::API.'/calendars/'.rawurlencode('team@group.calendar.google.com').'/events*' => Http::response(['items' => [
                ['id' => 'holiday', 'summary' => 'عطلة', 'start' => ['date' => '2026-10-14'], 'end' => ['date' => '2026-10-15']],
            ]]),
        ]);
        $this->actAs([StaffAbility::OpsPhotography->value, StaffAbility::OpsPhotographyAll->value]);

        $this->getJson('/api/admin/photography/calendar/calendars')
            ->assertOk()
            ->assertJsonPath('data.calendars.0.id', self::MAIN)
            ->assertJsonPath('data.calendars.0.main', true)
            ->assertJsonPath('data.calendars.0.writable', true)
            ->assertJsonPath('data.calendars.1.writable', false)
            ->assertJsonPath('data.can_manage', true);

        $response = $this->getJson('/api/admin/photography/calendar/events?'.http_build_query([
            'from' => '2026-10-11T00:00:00+03:00',
            'to' => '2026-10-18T00:00:00+03:00',
            'calendars' => [self::MAIN, 'team@group.calendar.google.com', 'unknown@example.com'],
        ]))->assertOk();

        $events = collect($response->json('data'))->keyBy('id');
        $this->assertSame(['meeting', 'holiday'], $events->keys()->all());
        $this->assertTrue($events['meeting']['editable']);
        $this->assertSame('2026-10-12T10:00:00+03:00', $events['meeting']['start']);
        $this->assertTrue($events['holiday']['all_day']);
        $this->assertFalse($events['holiday']['editable']);
    }

    public function test_a_photographer_reads_events_but_cannot_write_them(): void
    {
        Http::fake([
            self::API.'/users/me/calendarList*' => Http::response(['items' => [['id' => self::MAIN, 'summary' => 'التصوير', 'accessRole' => 'owner']]]),
            self::API.'/calendars/*' => Http::response(['items' => [$this->item('meeting', '2026-10-12T10:00:00+03:00', '2026-10-12T11:00:00+03:00')]]),
        ]);
        $this->actAs([StaffAbility::OpsPhotography->value]);

        $this->getJson('/api/admin/photography/calendar/events?'.http_build_query([
            'from' => '2026-10-11', 'to' => '2026-10-18', 'calendars' => [self::MAIN],
        ]))->assertOk()->assertJsonPath('data.0.editable', false);

        $this->postJson('/api/admin/photography/calendar/events', [
            'calendar_id' => self::MAIN, 'title' => 'ممنوع', 'start' => '2026-10-12T10:00',
        ])->assertForbidden();
        $this->deleteJson('/api/admin/photography/calendar/events', [
            'calendar_id' => self::MAIN, 'event_id' => 'meeting',
        ])->assertForbidden();
    }

    public function test_staff_create_an_event_with_guests_reminders_and_a_weekly_rule(): void
    {
        Http::fake([
            self::API.'/users/me/calendarList*' => Http::response(['items' => [['id' => self::MAIN, 'summary' => 'التصوير', 'accessRole' => 'owner']]]),
            self::API.'/calendars/'.rawurlencode(self::MAIN).'/events*' => fn (Request $request) => Http::response(
                $this->item('new', $request['start']['dateTime'], $request['end']['dateTime'], ['summary' => $request['summary'], 'recurrence' => $request['recurrence']]),
            ),
        ]);
        $this->actAs([StaffAbility::OpsPhotography->value, StaffAbility::OpsPhotographyAll->value]);

        $this->postJson('/api/admin/photography/calendar/events', [
            'calendar_id' => self::MAIN,
            'title' => 'اجتماع المحتوى',
            'start' => '2026-10-12T10:00',
            'end' => '2026-10-12T11:30',
            'attendees' => ['media@hoc.agency'],
            'reminders' => ['use_default' => false, 'overrides' => [['method' => 'popup', 'minutes' => 30]]],
            'recurrence' => ['RRULE:FREQ=WEEKLY;BYDAY=MO'],
            'color_id' => '5',
            'notify' => true,
        ])->assertCreated()
            ->assertJsonPath('data.id', 'new')
            ->assertJsonPath('data.title', 'اجتماع المحتوى');

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), 'sendUpdates=all')
                && $request['start'] === ['dateTime' => '2026-10-12T10:00:00+03:00', 'timeZone' => 'Asia/Damascus']
                && $request['end']['dateTime'] === '2026-10-12T11:30:00+03:00'
                && $request['attendees'] === [['email' => 'media@hoc.agency']]
                && $request['reminders'] === ['useDefault' => false, 'overrides' => [['method' => 'popup', 'minutes' => 30]]]
                && $request['recurrence'] === ['RRULE:FREQ=WEEKLY;BYDAY=MO']
                && $request['colorId'] === '5';
        });
    }

    public function test_a_shoot_event_is_refused_by_the_general_editor(): void
    {
        Http::fake([
            self::API.'/users/me/calendarList*' => Http::response(['items' => [['id' => self::MAIN, 'summary' => 'التصوير', 'accessRole' => 'owner']]]),
            self::API.'/calendars/'.rawurlencode(self::MAIN).'/events/shoot' => Http::response($this->item('shoot', '2026-10-12T13:00:00+03:00', '2026-10-12T16:00:00+03:00', [
                'extendedProperties' => ['private' => ['hoc_booking' => '5']],
            ])),
        ]);
        $this->actAs([StaffAbility::OpsPhotography->value, StaffAbility::OpsPhotographyAll->value]);

        $this->patchJson('/api/admin/photography/calendar/events', [
            'calendar_id' => self::MAIN, 'event_id' => 'shoot', 'start' => '2026-10-13T13:00',
        ])->assertStatus(422)->assertJsonValidationErrors('event_id');

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH');
    }

    public function test_moving_one_occurrence_of_a_series_for_all_events_moves_the_series_and_its_weekday(): void
    {
        $master = $this->item('series', '2026-10-05T09:00:00+03:00', '2026-10-05T10:00:00+03:00', ['recurrence' => ['RRULE:FREQ=WEEKLY;BYDAY=MO']]);
        $instance = $this->item('series_20261012T060000Z', '2026-10-12T09:00:00+03:00', '2026-10-12T10:00:00+03:00', [
            'recurringEventId' => 'series',
            'originalStartTime' => ['dateTime' => '2026-10-12T09:00:00+03:00'],
        ]);
        Http::fake([
            self::API.'/users/me/calendarList*' => Http::response(['items' => [['id' => self::MAIN, 'summary' => 'التصوير', 'accessRole' => 'owner']]]),
            self::API.'/calendars/'.rawurlencode(self::MAIN).'/events/series_20261012T060000Z' => Http::response($instance),
            self::API.'/calendars/'.rawurlencode(self::MAIN).'/events/series*' => fn (Request $request) => Http::response($request->method() === 'PATCH' ? [...$master, ...$request->data()] : $master),
        ]);
        $this->actAs([StaffAbility::OpsPhotography->value, StaffAbility::OpsPhotographyAll->value]);

        $this->patchJson('/api/admin/photography/calendar/events', [
            'calendar_id' => self::MAIN,
            'event_id' => 'series_20261012T060000Z',
            'scope' => 'all',
            'all_day' => false,
            'start' => '2026-10-13T11:00:00+03:00',
            'end' => '2026-10-13T12:30:00+03:00',
        ])->assertOk();

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'PATCH'
                && str_contains($request->url(), '/events/series?')
                && $request['start']['dateTime'] === '2026-10-06T11:00:00+03:00'
                && $request['end']['dateTime'] === '2026-10-06T12:30:00+03:00'
                && $request['recurrence'] === ['RRULE:FREQ=WEEKLY;BYDAY=TU'];
        });
    }

    public function test_editing_this_and_following_splits_the_series(): void
    {
        $master = $this->item('series', '2026-10-05T09:00:00+03:00', '2026-10-05T10:00:00+03:00', [
            'summary' => 'تدريب',
            'recurrence' => ['RRULE:FREQ=WEEKLY;BYDAY=MO;COUNT=6'],
        ]);
        $instance = $this->item('series_20261019T060000Z', '2026-10-19T09:00:00+03:00', '2026-10-19T10:00:00+03:00', [
            'recurringEventId' => 'series',
            'originalStartTime' => ['dateTime' => '2026-10-19T09:00:00+03:00'],
        ]);
        Http::fake([
            self::API.'/users/me/calendarList*' => Http::response(['items' => [['id' => self::MAIN, 'summary' => 'التصوير', 'accessRole' => 'owner']]]),
            self::API.'/calendars/'.rawurlencode(self::MAIN).'/events/series_20261019T060000Z' => Http::response($instance),
            self::API.'/calendars/'.rawurlencode(self::MAIN).'/events/series/instances*' => Http::response(['items' => [['id' => 'a'], ['id' => 'b']]]),
            self::API.'/calendars/'.rawurlencode(self::MAIN).'/events/series*' => fn (Request $request) => Http::response($request->method() === 'PATCH' ? [...$master, ...$request->data()] : $master),
            self::API.'/calendars/'.rawurlencode(self::MAIN).'/events?*' => fn (Request $request) => Http::response([...$request->data(), 'id' => 'series2']),
        ]);
        $this->actAs([StaffAbility::OpsPhotography->value, StaffAbility::OpsPhotographyAll->value]);

        $this->patchJson('/api/admin/photography/calendar/events', [
            'calendar_id' => self::MAIN,
            'event_id' => 'series_20261019T060000Z',
            'scope' => 'following',
            'title' => 'تدريب متقدم',
        ])->assertOk()->assertJsonPath('data.id', 'series2');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && str_contains($request->url(), '/events/series?')
            && $request['recurrence'] === ['RRULE:FREQ=WEEKLY;BYDAY=MO;UNTIL=20261019T055959Z']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request['summary'] === 'تدريب متقدم'
            && $request['start']['dateTime'] === '2026-10-19T09:00:00+03:00'
            && $request['recurrence'] === ['RRULE:FREQ=WEEKLY;BYDAY=MO;COUNT=4']);
    }

    public function test_deleting_this_and_following_ends_the_series(): void
    {
        $master = $this->item('series', '2026-10-05T09:00:00+03:00', '2026-10-05T10:00:00+03:00', ['recurrence' => ['RRULE:FREQ=DAILY']]);
        $instance = $this->item('series_20261008T060000Z', '2026-10-08T09:00:00+03:00', '2026-10-08T10:00:00+03:00', [
            'recurringEventId' => 'series',
            'originalStartTime' => ['dateTime' => '2026-10-08T09:00:00+03:00'],
        ]);
        Http::fake([
            self::API.'/users/me/calendarList*' => Http::response(['items' => [['id' => self::MAIN, 'summary' => 'التصوير', 'accessRole' => 'owner']]]),
            self::API.'/calendars/'.rawurlencode(self::MAIN).'/events/series_20261008T060000Z' => Http::response($instance),
            self::API.'/calendars/'.rawurlencode(self::MAIN).'/events/series*' => Http::response($master),
        ]);
        $this->actAs([StaffAbility::OpsPhotography->value, StaffAbility::OpsPhotographyAll->value]);

        $this->deleteJson('/api/admin/photography/calendar/events', [
            'calendar_id' => self::MAIN, 'event_id' => 'series_20261008T060000Z', 'scope' => 'following',
        ])->assertOk();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request['recurrence'] === ['RRULE:FREQ=DAILY;UNTIL=20261008T055959Z']);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_the_calendar_reads_past_shoots_in_the_visible_range(): void
    {
        Http::fake();
        $request = ServiceRequest::factory()->create();
        PhotographyBooking::query()->create([
            'request_id' => $request->id,
            'starts_at' => '2026-09-14 13:00:00',
            'ends_at' => '2026-09-14 16:00:00',
            'status' => PhotographyBooking::DONE,
        ]);
        PhotographyBooking::query()->create([
            'request_id' => $request->id,
            'starts_at' => '2026-09-15 13:00:00',
            'ends_at' => '2026-09-15 16:00:00',
            'status' => PhotographyBooking::CANCELLED,
        ]);
        $this->actAs([StaffAbility::OpsPhotography->value]);

        $this->getJson('/api/admin/photography/calendar/bookings?from=2026-09-01&to=2026-10-01')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', PhotographyBooking::DONE);

        $this->getJson('/api/admin/photography/calendar/bookings?from=2026-09-01&to=2026-10-01&closed=1')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_roles_limit_who_sees_and_edits_a_calendar(): void
    {
        $team = 'team@group.calendar.google.com';
        Http::fake([
            self::API.'/users/me/calendarList*' => Http::response(['items' => [
                ['id' => self::MAIN, 'summary' => 'التصوير', 'accessRole' => 'owner'],
                ['id' => $team, 'summary' => 'الفريق', 'accessRole' => 'writer'],
            ]]),
            self::API.'/calendars/'.rawurlencode($team).'/events*' => fn (Request $request) => Http::response(
                $request->method() === 'POST'
                    ? $this->item('new', '2026-10-12T10:00:00+03:00', '2026-10-12T11:00:00+03:00')
                    : ['items' => [$this->item('meeting', '2026-10-12T10:00:00+03:00', '2026-10-12T11:00:00+03:00')]],
            ),
        ]);
        $editors = Role::query()->create(['name' => 'المحتوى', 'abilities' => [StaffAbility::OpsPhotography->value]]);
        $viewers = Role::query()->create(['name' => 'المبيعات', 'abilities' => [StaffAbility::OpsPhotography->value]]);
        $outside = Role::query()->create(['name' => 'التصميم', 'abilities' => [StaffAbility::OpsPhotography->value]]);

        $this->actAs([StaffAbility::OpsPhotography->value, StaffAbility::OpsPhotographyAll->value]);
        $this->putJson('/api/admin/photography/calendar/permissions', [
            'calendar_id' => $team,
            'roles' => [$editors->id => 'edit', $viewers->id => 'view', $outside->id => 'none'],
        ])->assertOk()->assertJsonPath('data.restricted', true);
        $this->getJson('/api/admin/photography/calendar/permissions?calendar_id='.rawurlencode($team))
            ->assertOk()
            ->assertJsonFragment(['id' => $editors->id, 'level' => 'edit'])
            ->assertJsonFragment(['id' => $viewers->id, 'level' => 'view'])
            ->assertJsonFragment(['id' => $outside->id, 'level' => 'none']);

        $query = '/api/admin/photography/calendar/events?'.http_build_query(['from' => '2026-10-11', 'to' => '2026-10-18', 'calendars' => [$team]]);
        $create = ['calendar_id' => $team, 'title' => 'اجتماع', 'start' => '2026-10-12T10:00'];

        Sanctum::actingAs(User::factory()->create(['role_id' => $editors->id]));
        $this->getJson('/api/admin/photography/calendar/calendars')->assertJsonFragment(['id' => $team, 'writable' => true, 'restricted' => true]);
        $this->getJson($query)->assertJsonPath('data.0.editable', true);
        $this->postJson('/api/admin/photography/calendar/events', $create)->assertCreated();
        $this->getJson('/api/admin/photography/calendar/permissions?calendar_id='.rawurlencode($team))->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role_id' => $viewers->id]));
        $this->getJson($query)->assertJsonPath('data.0.editable', false);
        $this->postJson('/api/admin/photography/calendar/events', $create)->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role_id' => $outside->id]));
        $calendars = collect($this->getJson('/api/admin/photography/calendar/calendars')->json('data.calendars'))->pluck('id')->all();
        $this->assertSame([self::MAIN], $calendars);
        $this->getJson($query)->assertJsonCount(0, 'data');
        $this->postJson('/api/admin/photography/calendar/events', $create)->assertStatus(422);
    }

    public function test_recurrence_rules_shift_and_end(): void
    {
        $this->assertSame(['RRULE:FREQ=WEEKLY;BYDAY=SU,TU'], RecurrenceRule::shiftDays(['RRULE:FREQ=WEEKLY;BYDAY=SA,MO'], 1));
        $this->assertSame(['RRULE:FREQ=MONTHLY;BYDAY=2SA'], RecurrenceRule::shiftDays(['RRULE:FREQ=MONTHLY;BYDAY=2MO'], -2));
        $this->assertSame(
            ['RRULE:FREQ=WEEKLY;UNTIL=20261018', 'EXDATE;VALUE=DATE:20261012'],
            RecurrenceRule::until(['RRULE:FREQ=WEEKLY;COUNT=3', 'EXDATE;VALUE=DATE:20261012'], Carbon::parse('2026-10-19', 'Asia/Damascus'), true),
        );
        $this->assertSame(3, RecurrenceRule::count(['RRULE:FREQ=DAILY;COUNT=3']));
    }

    /** @param  list<string>  $abilities */
    private function actAs(array $abilities): void
    {
        $role = Role::query()->create(['name' => 'تصوير '.count($abilities), 'abilities' => $abilities]);
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id]));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function item(string $id, string $start, string $end, array $extra = []): array
    {
        return [
            'id' => $id,
            'status' => 'confirmed',
            'summary' => 'حدث',
            'start' => ['dateTime' => $start, 'timeZone' => 'Asia/Damascus'],
            'end' => ['dateTime' => $end, 'timeZone' => 'Asia/Damascus'],
            'organizer' => ['email' => self::MAIN, 'self' => true],
            ...$extra,
        ];
    }
}
