<?php

namespace App\Actions;

use App\Services\GoogleCalendarClient;
use App\Support\RecurrenceRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Google Calendar events that are not photography bookings, for the photography tab.
 * Shoots stay with BookPhotographySlot: their events carry a hoc_booking key and are
 * refused here, so a drag in the tab always goes through the booking rules.
 */
class ManageCalendarEvents
{
    public const ZONE = 'Asia/Damascus';

    private const CALENDARS_KEY = 'hoc:gcal:calendars';

    public function __construct(private GoogleCalendarClient $google) {}

    public function configured(): bool
    {
        return $this->google->configured();
    }

    public function serviceEmail(): ?string
    {
        return $this->google->serviceEmail();
    }

    /**
     * @return list<array{id: string, summary: string, description: string|null, color: string|null, foreground: string|null, access_role: string, main: bool, writable: bool}>
     */
    public function calendars(bool $fresh = false): array
    {
        if (! $fresh) {
            $cached = Cache::get(self::CALENDARS_KEY);
            if (is_array($cached) && $cached !== []) {
                return $cached;
            }
        }
        $rows = array_map(fn (array $row): array => [
            ...$row,
            'writable' => in_array($row['access_role'], ['owner', 'writer'], true),
        ], $this->google->calendars());
        Cache::put(self::CALENDARS_KEY, $rows, $this->google->lastError() === null ? 300 : 30);

        return $rows;
    }

    /** @return array{id: string, summary: string, description: string|null, color: string|null, foreground: string|null, access_role: string, main: bool, writable: bool} */
    public function calendar(string $calendarId): array
    {
        foreach ($this->calendars() as $row) {
            if ($row['id'] === $calendarId) {
                return $row;
            }
        }

        throw ValidationException::withMessages(['calendar_id' => 'هذا التقويم ليس في قائمة التقاويم.']);
    }

    /** @return array{id: string, summary: string, description: string|null, color: string|null, foreground: string|null, access_role: string, main: bool, writable: bool} */
    public function subscribe(string $calendarId): array
    {
        $row = $this->google->subscribe($calendarId);
        if ($row === null) {
            $email = $this->serviceEmail();
            $this->fail('calendar_id', 'تعذرت إضافة التقويم. شاركه أولاً مع '.($email ?? 'حساب الخدمة').' بصلاحية "إجراء تغييرات على الأحداث".');
        }

        foreach ($this->calendars(true) as $fresh) {
            if ($fresh['id'] === $row['id']) {
                return $fresh;
            }
        }

        return [...$row, 'writable' => in_array($row['access_role'], ['owner', 'writer'], true)];
    }

    public function unsubscribe(string $calendarId): void
    {
        $calendar = $this->calendar($calendarId);
        if ($calendar['main']) {
            $this->fail('calendar_id', 'التقويم الرئيسي للتصوير لا يُزال من القائمة.');
        }
        if (! $this->google->unsubscribe($calendarId)) {
            $this->fail('calendar_id', 'تعذرت إزالة التقويم.');
        }
        $this->calendars(true);
    }

    /**
     * @param  list<string>  $calendarIds
     * @return array{events: list<array<string, mixed>>, errors: list<array{calendar_id: string, message: string}>}
     */
    public function events(array $calendarIds, Carbon $from, Carbon $to, ?string $search, bool $canManage): array
    {
        $known = [];
        foreach ($this->calendars() as $row) {
            $known[$row['id']] = $row;
        }
        $events = [];
        $errors = [];
        foreach (array_unique($calendarIds) as $calendarId) {
            $calendar = $known[$calendarId] ?? null;
            if ($calendar === null) {
                continue;
            }
            $items = $this->google->events($calendarId, $from, $to, $search);
            if ($items === null) {
                $errors[] = ['calendar_id' => $calendarId, 'message' => $this->google->lastError() ?? 'تعذرت قراءة التقويم.'];

                continue;
            }
            foreach ($items as $item) {
                if ($calendar['main'] && $this->bookingKey($item) !== null) {
                    continue;
                }
                $row = $this->row($item, $calendar, $canManage);
                if ($row !== null) {
                    $events[] = $row;
                }
            }
        }

        return ['events' => $events, 'errors' => $errors];
    }

    /** One event, with the series rule when it is an occurrence. */
    public function find(string $calendarId, string $eventId, bool $canManage): array
    {
        $calendar = $this->calendar($calendarId);
        $item = $this->google->event($calendarId, $eventId) ?? $this->fail('event_id', $this->google->lastError() ?? 'الحدث غير موجود.');
        $row = $this->row($item, $calendar, $canManage) ?? $this->fail('event_id', 'الحدث بلا وقت.');
        if (filled($item['recurringEventId'] ?? null)) {
            $master = $this->google->event($calendarId, (string) $item['recurringEventId']);
            $row['recurrence'] = $master['recurrence'] ?? null;
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $calendar = $this->writable((string) $input['calendar_id']);
        $body = $this->clean($this->payload($input, null));
        $body['summary'] ??= '(بلا عنوان)';
        $item = $this->google->insert($calendar['id'], $body, $this->sendUpdates($input))
            ?? $this->fail('title', $this->google->lastError() ?? 'رفض جوجل إنشاء الحدث.');

        return $this->result($item, $calendar);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(string $calendarId, string $eventId, array $input, string $scope): array
    {
        $calendar = $this->writable($calendarId);
        $item = $this->google->event($calendarId, $eventId) ?? $this->fail('event_id', $this->google->lastError() ?? 'الحدث غير موجود.');
        $this->guardBooking($calendar, $item);
        $send = $this->sendUpdates($input);
        $masterId = filled($item['recurringEventId'] ?? null) ? (string) $item['recurringEventId'] : null;

        if ($masterId === null || $scope === 'this') {
            $body = $this->payload($input, $item);
            if ($masterId !== null) {
                unset($body['recurrence']);
            }
            $saved = $body === [] ? $item : ($this->google->patch($calendarId, $eventId, $body, $send)
                ?? $this->fail('event_id', $this->google->lastError() ?? 'رفض جوجل التعديل.'));
        } else {
            $master = $this->google->event($calendarId, $masterId) ?? $this->fail('event_id', $this->google->lastError() ?? 'السلسلة غير موجودة.');
            $saved = $scope === 'following' && $this->instant($this->originalStart($item))->gt($this->instant($master['start']))
                ? $this->splitSeries($calendarId, $item, $master, $input, $send)
                : $this->updateSeries($calendarId, $item, $master, $input, $send);
        }

        $target = (string) ($input['target_calendar_id'] ?? '');
        if ($target !== '' && $target !== $calendarId) {
            $destination = $this->writable($target);
            $moveId = ($scope === 'this' || $masterId === null) ? (string) $saved['id'] : (string) ($saved['recurringEventId'] ?? $saved['id']);
            $saved = $this->google->move($calendarId, $moveId, $destination['id'], $send)
                ?? $this->fail('target_calendar_id', $this->google->lastError() ?? 'تعذر نقل الحدث إلى التقويم الآخر.');
            $calendar = $destination;
        }

        return $this->result($saved, $calendar);
    }

    public function delete(string $calendarId, string $eventId, string $scope, bool $notify): void
    {
        $calendar = $this->writable($calendarId);
        $item = $this->google->event($calendarId, $eventId);
        if ($item === null) {
            return;
        }
        $this->guardBooking($calendar, $item);
        $send = $notify ? 'all' : 'none';
        $masterId = filled($item['recurringEventId'] ?? null) ? (string) $item['recurringEventId'] : null;

        if ($masterId === null || $scope === 'this') {
            $this->google->remove($calendarId, $eventId, $send) || $this->fail('event_id', $this->google->lastError() ?? 'رفض جوجل الحذف.');

            return;
        }

        $master = $this->google->event($calendarId, $masterId) ?? $this->fail('event_id', $this->google->lastError() ?? 'السلسلة غير موجودة.');
        $original = $this->instant($this->originalStart($item));
        if ($scope === 'following' && $original->gt($this->instant($master['start']))) {
            $this->google->patch($calendarId, $masterId, [
                'recurrence' => RecurrenceRule::until($master['recurrence'] ?? [], $original, isset($master['start']['date'])),
            ], $send) ?? $this->fail('event_id', $this->google->lastError() ?? 'رفض جوجل إنهاء السلسلة.');

            return;
        }

        $this->google->remove($calendarId, $masterId, $send) || $this->fail('event_id', $this->google->lastError() ?? 'رفض جوجل حذف السلسلة.');
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array{id: string, writable: bool, main: bool}  $calendar
     * @return array<string, mixed>|null
     */
    public function row(array $item, array $calendar, bool $canManage): ?array
    {
        $start = $item['start'] ?? null;
        $end = $item['end'] ?? null;
        if (! is_array($start) || ! is_array($end) || ! is_string($item['id'] ?? null)) {
            return null;
        }
        $allDay = isset($start['date']);
        $organizerSelf = (bool) ($item['organizer']['self'] ?? ! isset($item['organizer']));
        $editable = $canManage
            && $calendar['writable']
            && ! ($item['locked'] ?? false)
            && ! ($item['privateCopy'] ?? false)
            && ($organizerSelf || (bool) ($item['guestsCanModify'] ?? false));
        $meet = $item['hangoutLink'] ?? null;
        foreach ($item['conferenceData']['entryPoints'] ?? [] as $entry) {
            if (($entry['entryPointType'] ?? '') === 'video' && is_string($entry['uri'] ?? null)) {
                $meet ??= $entry['uri'];
            }
        }

        return [
            'id' => $item['id'],
            'calendar_id' => $calendar['id'],
            'title' => (string) ($item['summary'] ?? ''),
            'description' => $item['description'] ?? null,
            'location' => $item['location'] ?? null,
            'all_day' => $allDay,
            'start' => $allDay ? (string) $start['date'] : $this->instant($start)->toIso8601String(),
            'end' => $allDay ? (string) ($end['date'] ?? $start['date']) : $this->instant($end)->toIso8601String(),
            'color_id' => isset($item['colorId']) ? (string) $item['colorId'] : null,
            'recurring_event_id' => $item['recurringEventId'] ?? null,
            'recurrence' => $item['recurrence'] ?? null,
            'attendees' => array_values(array_map(fn (array $attendee): array => [
                'email' => (string) ($attendee['email'] ?? ''),
                'name' => $attendee['displayName'] ?? null,
                'response' => $attendee['responseStatus'] ?? 'needsAction',
                'organizer' => (bool) ($attendee['organizer'] ?? false),
                'optional' => (bool) ($attendee['optional'] ?? false),
            ], array_filter($item['attendees'] ?? [], fn ($attendee): bool => is_array($attendee) && ! ($attendee['resource'] ?? false)))),
            'reminders' => [
                'use_default' => (bool) ($item['reminders']['useDefault'] ?? true),
                'overrides' => array_values(array_map(fn (array $override): array => [
                    'method' => (string) ($override['method'] ?? 'popup'),
                    'minutes' => (int) ($override['minutes'] ?? 10),
                ], $item['reminders']['overrides'] ?? [])),
            ],
            'meet_url' => is_string($meet) ? $meet : null,
            'html_link' => $item['htmlLink'] ?? null,
            'status' => (string) ($item['status'] ?? 'confirmed'),
            'transparency' => (string) ($item['transparency'] ?? 'opaque'),
            'visibility' => (string) ($item['visibility'] ?? 'default'),
            'organizer' => $item['organizer']['email'] ?? null,
            'editable' => $editable,
        ];
    }

    /**
     * Only the fields the dashboard sent. A drag sends times alone.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    private function payload(array $input, ?array $existing): array
    {
        $body = [];
        if (array_key_exists('title', $input)) {
            $body['summary'] = trim((string) $input['title']) !== '' ? trim((string) $input['title']) : '(بلا عنوان)';
        }
        foreach (['description' => 'description', 'location' => 'location'] as $field => $key) {
            if (array_key_exists($field, $input)) {
                $body[$key] = $input[$field] === null ? '' : (string) $input[$field];
            }
        }
        if (array_key_exists('color_id', $input)) {
            $body['colorId'] = filled($input['color_id']) ? (string) $input['color_id'] : null;
        }
        if (array_key_exists('start', $input)) {
            [$body['start'], $body['end']] = $this->times($input);
        }
        if (array_key_exists('attendees', $input)) {
            $current = [];
            foreach ($existing['attendees'] ?? [] as $attendee) {
                if (is_array($attendee) && is_string($attendee['email'] ?? null)) {
                    $current[strtolower($attendee['email'])] = $attendee;
                }
            }
            $body['attendees'] = array_values(array_map(
                fn (string $email): array => $current[strtolower($email)] ?? ['email' => $email],
                array_values(array_unique(array_map('strval', (array) $input['attendees']))),
            ));
        }
        if (array_key_exists('reminders', $input) && is_array($input['reminders'])) {
            $useDefault = (bool) ($input['reminders']['use_default'] ?? true);
            $body['reminders'] = $useDefault ? ['useDefault' => true] : [
                'useDefault' => false,
                'overrides' => array_values(array_map(fn (array $override): array => [
                    'method' => ($override['method'] ?? 'popup') === 'email' ? 'email' : 'popup',
                    'minutes' => max(0, min(40320, (int) ($override['minutes'] ?? 10))),
                ], array_slice((array) ($input['reminders']['overrides'] ?? []), 0, 5))),
            ];
        }
        if (array_key_exists('recurrence', $input)) {
            $body['recurrence'] = array_values(array_filter((array) ($input['recurrence'] ?? []), fn ($line): bool => is_string($line) && $line !== ''));
        }
        if (array_key_exists('transparency', $input)) {
            $body['transparency'] = $input['transparency'] === 'transparent' ? 'transparent' : 'opaque';
        }
        if (array_key_exists('visibility', $input)) {
            $body['visibility'] = in_array($input['visibility'], ['public', 'private'], true) ? $input['visibility'] : 'default';
        }
        if (($input['meet'] ?? false) === true && ! isset($existing['conferenceData'])) {
            $body['conferenceData'] = [
                'createRequest' => [
                    'requestId' => (string) Str::uuid(),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ],
            ];
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, string|null>, 1: array<string, string|null>}
     */
    private function times(array $input): array
    {
        if ((bool) ($input['all_day'] ?? false)) {
            $start = Carbon::parse(substr((string) $input['start'], 0, 10), self::ZONE)->startOfDay();
            $end = filled($input['end'] ?? null) ? Carbon::parse(substr((string) $input['end'], 0, 10), self::ZONE)->startOfDay() : $start->copy()->addDay();
            if ($end->lte($start)) {
                $end = $start->copy()->addDay();
            }

            return [
                ['date' => $start->format('Y-m-d'), 'dateTime' => null, 'timeZone' => null],
                ['date' => $end->format('Y-m-d'), 'dateTime' => null, 'timeZone' => null],
            ];
        }

        $start = Carbon::parse((string) $input['start'], self::ZONE)->timezone(self::ZONE);
        $end = filled($input['end'] ?? null) ? Carbon::parse((string) $input['end'], self::ZONE)->timezone(self::ZONE) : $start->copy()->addHour();
        if ($end->lte($start)) {
            $end = $start->copy()->addHour();
        }

        return [
            ['dateTime' => $start->toIso8601String(), 'timeZone' => self::ZONE, 'date' => null],
            ['dateTime' => $end->toIso8601String(), 'timeZone' => self::ZONE, 'date' => null],
        ];
    }

    /**
     * Applies the edit to the whole series. A new time on one occurrence moves every
     * occurrence by the same days and to the same clock time.
     *
     * @param  array<string, mixed>  $instance
     * @param  array<string, mixed>  $master
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function updateSeries(string $calendarId, array $instance, array $master, array $input, string $send): array
    {
        $body = $this->payload($input, $master);
        if (isset($body['start'], $body['end'])) {
            $body = $this->shiftSeries($master, $instance, $body);
        }
        if ($body === []) {
            return $master;
        }

        return $this->google->patch($calendarId, (string) $master['id'], $body, $send)
            ?? $this->fail('event_id', $this->google->lastError() ?? 'رفض جوجل تعديل السلسلة.');
    }

    /**
     * Ends the old series before this occurrence and starts a new one from it with the edit.
     *
     * @param  array<string, mixed>  $instance
     * @param  array<string, mixed>  $master
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function splitSeries(string $calendarId, array $instance, array $master, array $input, string $send): array
    {
        $masterId = (string) $master['id'];
        $original = $this->instant($this->originalStart($instance));
        $lines = array_values($master['recurrence'] ?? []);
        $count = RecurrenceRule::count($lines);
        $remaining = null;
        if ($count !== null) {
            $before = $this->google->countInstancesBefore($calendarId, $masterId, $original);
            $remaining = $before === null ? $count : max(1, $count - $before);
        }

        $this->google->patch($calendarId, $masterId, [
            'recurrence' => RecurrenceRule::until($lines, $original, isset($master['start']['date'])),
        ], $send) ?? $this->fail('event_id', $this->google->lastError() ?? 'رفض جوجل إنهاء السلسلة.');

        $copy = array_intersect_key($master, array_flip([
            'summary', 'description', 'location', 'colorId', 'attendees', 'reminders', 'transparency',
            'visibility', 'guestsCanModify', 'guestsCanInviteOthers', 'guestsCanSeeOtherGuests',
        ]));
        $copy['start'] = $instance['start'];
        $copy['end'] = $instance['end'];
        $copy['recurrence'] = RecurrenceRule::withCount($lines, $remaining);
        $changes = $this->payload($input, $master);
        if (isset($changes['start']) && ! array_key_exists('recurrence', $changes)) {
            $days = $this->dayShift($original, $this->instant($changes['start']));
            $copy['recurrence'] = RecurrenceRule::shiftDays($copy['recurrence'], $days);
        }
        if (array_key_exists('recurrence', $changes) && $changes['recurrence'] === []) {
            unset($changes['recurrence'], $copy['recurrence']);
        }

        $created = $this->google->insert($calendarId, $this->clean(array_merge($copy, $changes)), $send);
        if ($created === null) {
            $error = $this->google->lastError();
            $this->google->patch($calendarId, $masterId, ['recurrence' => $lines], 'none');
            $this->fail('event_id', $error ?? 'رفض جوجل إنشاء بقية السلسلة.');
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $master
     * @param  array<string, mixed>  $instance
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function shiftSeries(array $master, array $instance, array $body): array
    {
        $original = $this->instant($this->originalStart($instance));
        $newStart = $this->instant($body['start']);
        $newEnd = $this->instant($body['end']);
        $days = $this->dayShift($original, $newStart);
        $firstDay = $this->instant($master['start'])->startOfDay()->addDays($days);

        if (isset($body['start']['date'])) {
            $span = max(1, $this->dayShift($newStart, $newEnd));
            $body['start'] = ['date' => $firstDay->format('Y-m-d'), 'dateTime' => null, 'timeZone' => null];
            $body['end'] = ['date' => $firstDay->copy()->addDays($span)->format('Y-m-d'), 'dateTime' => null, 'timeZone' => null];
        } else {
            $start = $firstDay->copy()->setTime($newStart->hour, $newStart->minute);
            $body['start'] = ['dateTime' => $start->toIso8601String(), 'timeZone' => self::ZONE, 'date' => null];
            $body['end'] = ['dateTime' => $start->copy()->addSeconds((int) $newStart->diffInSeconds($newEnd))->toIso8601String(), 'timeZone' => self::ZONE, 'date' => null];
        }
        if (! array_key_exists('recurrence', $body)) {
            $shifted = RecurrenceRule::shiftDays(array_values($master['recurrence'] ?? []), $days);
            if ($shifted !== array_values($master['recurrence'] ?? [])) {
                $body['recurrence'] = $shifted;
            }
        }

        return $body;
    }

    private function dayShift(Carbon $from, Carbon $to): int
    {
        return (int) round($from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay(), false));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function originalStart(array $item): array
    {
        $original = $item['originalStartTime'] ?? $item['start'] ?? [];

        return is_array($original) ? $original : [];
    }

    /** @param  array<string, mixed>  $time */
    private function instant(array $time): Carbon
    {
        if (filled($time['dateTime'] ?? null)) {
            return Carbon::parse((string) $time['dateTime'])->timezone(self::ZONE);
        }

        return Carbon::parse((string) ($time['date'] ?? 'now'), self::ZONE)->startOfDay();
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array{id: string, writable: bool, main: bool}  $calendar
     * @return array<string, mixed>
     */
    private function result(array $item, array $calendar): array
    {
        $row = $this->row($item, $calendar, true) ?? ['id' => $item['id'] ?? null, 'calendar_id' => $calendar['id']];
        $row['conference_failed'] = isset($item['_conference_failed']);

        return $row;
    }

    /** @return array{id: string, summary: string, description: string|null, color: string|null, foreground: string|null, access_role: string, main: bool, writable: bool} */
    private function writable(string $calendarId): array
    {
        $calendar = $this->calendar($calendarId);
        if (! $calendar['writable']) {
            $this->fail('calendar_id', 'حساب الخدمة يقرأ هذا التقويم فقط. شاركه بصلاحية "إجراء تغييرات على الأحداث".');
        }

        return $calendar;
    }

    /**
     * @param  array{main: bool}  $calendar
     * @param  array<string, mixed>  $item
     */
    private function guardBooking(array $calendar, array $item): void
    {
        if ($calendar['main'] && $this->bookingKey($item) !== null) {
            $this->fail('event_id', 'هذا موعد تصوير. عدّله أو ألغه من أزرار الموعد في التاب.');
        }
    }

    /** @param  array<string, mixed>  $item */
    private function bookingKey(array $item): ?string
    {
        $key = $item['extendedProperties']['private']['hoc_booking'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    /** @param  array<string, mixed>  $input */
    private function sendUpdates(array $input): string
    {
        return ($input['notify'] ?? false) === true ? 'all' : 'none';
    }

    /**
     * Drops nulls before a create, where Google has nothing to clear.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function clean(array $body): array
    {
        foreach ($body as $key => $value) {
            if ($value === null) {
                unset($body[$key]);
            } elseif (is_array($value) && ! array_is_list($value)) {
                $body[$key] = $this->clean($value);
            }
        }

        return $body;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
