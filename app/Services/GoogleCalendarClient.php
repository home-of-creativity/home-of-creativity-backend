<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GoogleCalendarClient
{
    private const API = 'https://www.googleapis.com/calendar/v3';

    private ?string $lastError = null;

    public function __construct(private GoogleServiceAccount $auth) {}

    public function configured(): bool
    {
        return $this->auth->configured()
            && filled(config('services.google.calendar_id'));
    }

    /** Google's own message for the last failed generic call, for the dashboard toast. */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function mainCalendarId(): string
    {
        return (string) config('services.google.calendar_id', 'primary') ?: 'primary';
    }

    /** The address a calendar must be shared with before it can be added. */
    public function serviceEmail(): ?string
    {
        return $this->auth->clientEmail();
    }

    /**
     * Calendars on the service account's list, with the main calendar always first.
     *
     * @return list<array{id: string, summary: string, description: string|null, color: string|null, foreground: string|null, access_role: string, main: bool}>
     */
    public function calendars(): array
    {
        $main = $this->mainCalendarId();
        $items = [];
        $pageToken = null;
        do {
            $response = $this->call('get', '/users/me/calendarList', array_filter([
                'maxResults' => 250,
                'pageToken' => $pageToken,
            ]));
            if ($response === null) {
                break;
            }
            foreach ($response['items'] ?? [] as $item) {
                if (is_array($item) && is_string($item['id'] ?? null)) {
                    $items[$item['id']] = $this->calendarRow($item, $main);
                }
            }
            $pageToken = $response['nextPageToken'] ?? null;
        } while (is_string($pageToken) && $pageToken !== '');

        $mainKey = $main === 'primary' ? $this->firstPrimaryId($items) ?? 'primary' : $main;
        if (! isset($items[$mainKey])) {
            $calendar = $this->call('get', '/calendars/'.rawurlencode($main));
            $items[$mainKey] = $this->calendarRow([
                'id' => $mainKey,
                'summary' => $calendar['summary'] ?? $main,
                'description' => $calendar['description'] ?? null,
                'accessRole' => 'owner',
            ], $main);
        }
        $items[$mainKey]['main'] = true;

        $rows = array_values($items);
        usort($rows, fn (array $a, array $b): int => [$b['main'] ? 1 : 0, $a['summary']] <=> [$a['main'] ? 1 : 0, $b['summary']]);

        return $rows;
    }

    /**
     * Adds a calendar that was shared with the service account to its list.
     *
     * @return array{id: string, summary: string, description: string|null, color: string|null, foreground: string|null, access_role: string, main: bool}|null
     */
    public function subscribe(string $calendarId): ?array
    {
        $item = $this->call('post', '/users/me/calendarList', [], ['id' => $calendarId]);

        return $item === null ? null : $this->calendarRow($item, $this->mainCalendarId());
    }

    public function unsubscribe(string $calendarId): bool
    {
        return $this->call('delete', '/users/me/calendarList/'.rawurlencode($calendarId)) !== null;
    }

    /**
     * Every event in a range, recurring series expanded into single instances.
     *
     * @return list<array<string, mixed>>|null
     */
    public function events(string $calendarId, CarbonInterface $from, CarbonInterface $to, ?string $search = null): ?array
    {
        $items = [];
        $pageToken = null;
        $pages = 0;
        do {
            $response = $this->call('get', '/calendars/'.rawurlencode($calendarId).'/events', array_filter([
                'timeMin' => $from->copy()->utc()->toIso8601ZuluString(),
                'timeMax' => $to->copy()->utc()->toIso8601ZuluString(),
                'singleEvents' => 'true',
                'orderBy' => 'startTime',
                'maxResults' => 2500,
                'q' => filled($search) ? $search : null,
                'pageToken' => $pageToken,
            ]));
            if ($response === null) {
                return $pages === 0 ? null : $items;
            }
            foreach ($response['items'] ?? [] as $item) {
                if (is_array($item) && ($item['status'] ?? '') !== 'cancelled') {
                    $items[] = $item;
                }
            }
            $pageToken = $response['nextPageToken'] ?? null;
            $pages++;
        } while (is_string($pageToken) && $pageToken !== '' && $pages < 4);

        return $items;
    }

    /** Occurrences of a series that start before $before, deleted ones included, as COUNT counts them. */
    public function countInstancesBefore(string $calendarId, string $eventId, CarbonInterface $before): ?int
    {
        $count = 0;
        $pageToken = null;
        $pages = 0;
        do {
            $response = $this->call('get', '/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId).'/instances', array_filter([
                'timeMax' => $before->copy()->utc()->toIso8601ZuluString(),
                'showDeleted' => 'true',
                'maxResults' => 2500,
                'pageToken' => $pageToken,
            ]));
            if ($response === null) {
                return null;
            }
            $count += count($response['items'] ?? []);
            $pageToken = $response['nextPageToken'] ?? null;
            $pages++;
        } while (is_string($pageToken) && $pageToken !== '' && $pages < 10);

        return $count;
    }

    /** @return array<string, mixed>|null */
    public function event(string $calendarId, string $eventId): ?array
    {
        return $this->call('get', '/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function insert(string $calendarId, array $payload, string $sendUpdates = 'none'): ?array
    {
        return $this->withConference($payload, fn (array $body, array $query): ?array => $this->call(
            'post',
            '/calendars/'.rawurlencode($calendarId).'/events',
            ['sendUpdates' => $sendUpdates, ...$query],
            $body,
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function patch(string $calendarId, string $eventId, array $payload, string $sendUpdates = 'none'): ?array
    {
        return $this->withConference($payload, fn (array $body, array $query): ?array => $this->call(
            'patch',
            '/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId),
            ['sendUpdates' => $sendUpdates, ...$query],
            $body,
        ));
    }

    public function remove(string $calendarId, string $eventId, string $sendUpdates = 'none'): bool
    {
        return $this->call(
            'delete',
            '/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId),
            ['sendUpdates' => $sendUpdates],
            gone: true,
        ) !== null;
    }

    /** @return array<string, mixed>|null */
    public function move(string $calendarId, string $eventId, string $destination, string $sendUpdates = 'none'): ?array
    {
        return $this->call(
            'post',
            '/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId).'/move',
            ['destination' => $destination, 'sendUpdates' => $sendUpdates],
        );
    }

    /**
     * A Meet link needs Google Workspace. When Google refuses it, the event is saved without one.
     *
     * @param  array<string, mixed>  $payload
     * @param  callable(array<string, mixed>, array<string, mixed>): (array<string, mixed>|null)  $send
     * @return array<string, mixed>|null
     */
    private function withConference(array $payload, callable $send): ?array
    {
        if (! array_key_exists('conferenceData', $payload)) {
            return $send($payload, []);
        }
        $result = $send($payload, ['conferenceDataVersion' => 1]);
        if ($result !== null) {
            return $result;
        }
        $error = $this->lastError;
        unset($payload['conferenceData']);
        $result = $send($payload, []);
        if ($result !== null) {
            $result['_conference_failed'] = $error ?? true;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>|null  The JSON body, an empty array for an empty reply, or null on failure.
     */
    private function call(string $method, string $path, array $query = [], ?array $body = null, bool $gone = false): ?array
    {
        $this->lastError = null;
        if (! $this->auth->configured()) {
            $this->lastError = 'Google Calendar is not configured.';

            return null;
        }
        $token = $this->auth->accessToken();
        if ($token === null) {
            $this->lastError = 'Google did not issue an access token.';

            return null;
        }

        try {
            $url = self::API.$path.($query === [] ? '' : '?'.http_build_query($query));
            $request = Http::withToken($token)->timeout(20)->acceptJson();
            $response = match ($method) {
                'get' => $request->get($url),
                'post' => $request->asJson()->post($url, $body ?? (object) []),
                'patch' => $request->asJson()->patch($url, $body ?? (object) []),
                'delete' => $request->delete($url),
            };

            if ($gone && in_array($response->status(), [404, 410], true)) {
                return [];
            }
            if (! $response->successful()) {
                $message = $response->json('error.message');
                $this->lastError = is_string($message) && $message !== '' ? $message : 'Google Calendar returned '.$response->status().'.';
                Log::warning('Google Calendar call failed.', [
                    'method' => $method,
                    'path' => $path,
                    'status' => $response->status(),
                    'error' => $this->lastError,
                ]);

                return null;
            }

            $json = $response->json();

            return is_array($json) ? $json : [];
        } catch (Throwable $exception) {
            $this->lastError = $exception->getMessage();
            Log::warning('Google Calendar call exception.', ['method' => $method, 'path' => $path, 'error' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{id: string, summary: string, description: string|null, color: string|null, foreground: string|null, access_role: string, main: bool}
     */
    private function calendarRow(array $item, string $main): array
    {
        $id = (string) $item['id'];

        return [
            'id' => $id,
            'summary' => (string) ($item['summaryOverride'] ?? $item['summary'] ?? $id),
            'description' => isset($item['description']) ? (string) $item['description'] : null,
            'color' => isset($item['backgroundColor']) ? (string) $item['backgroundColor'] : null,
            'foreground' => isset($item['foregroundColor']) ? (string) $item['foregroundColor'] : null,
            'access_role' => (string) ($item['accessRole'] ?? 'reader'),
            'main' => $id === $main || ($main === 'primary' && ($item['primary'] ?? false) === true),
        ];
    }

    /** @param  array<string, array<string, mixed>>  $items */
    private function firstPrimaryId(array $items): ?string
    {
        foreach ($items as $id => $item) {
            if ($item['main']) {
                return $id;
            }
        }

        return null;
    }

    public function createEvent(string $summary, string $description, CarbonInterface $startAt): ?string
    {
        if (! $this->configured()) {
            return null;
        }

        $token = $this->auth->accessToken();
        if ($token === null) {
            return null;
        }

        try {
            $calendarId = rawurlencode((string) config('services.google.calendar_id', 'primary'));
            $start = $startAt->copy()->timezone(config('app.timezone', 'UTC'));
            $end = $start->copy()->addHour();

            $response = Http::withToken($token)
                ->timeout(20)
                ->acceptJson()
                ->asJson()
                ->post(self::API.'/calendars/'.$calendarId.'/events', [
                    'summary' => $summary,
                    'description' => $description,
                    'start' => [
                        'dateTime' => $start->toIso8601String(),
                        'timeZone' => (string) config('app.timezone', 'UTC'),
                    ],
                    'end' => [
                        'dateTime' => $end->toIso8601String(),
                        'timeZone' => (string) config('app.timezone', 'UTC'),
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('Google Calendar createEvent failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $eventId = $response->json('id');

            return filled($eventId) ? (string) $eventId : null;
        } catch (Throwable $exception) {
            Log::warning('Google Calendar createEvent exception.', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function createShoot(
        string $summary,
        string $description,
        CarbonInterface $startAt,
        CarbonInterface $endAt,
        array $attendeeEmails = [],
        ?string $colorId = null,
        ?string $bookingKey = null,
    ): ?string {
        if (! $this->configured()) {
            return null;
        }

        $token = $this->auth->accessToken();
        if ($token === null) {
            return null;
        }

        try {
            $calendarId = rawurlencode((string) config('services.google.calendar_id', 'primary'));
            $start = $startAt->copy()->timezone('Asia/Damascus');
            $end = $endAt->copy()->timezone('Asia/Damascus');
            $payload = [
                'summary' => $summary,
                'description' => $description,
                'start' => [
                    'dateTime' => $start->toIso8601String(),
                    'timeZone' => 'Asia/Damascus',
                ],
                'end' => [
                    'dateTime' => $end->toIso8601String(),
                    'timeZone' => 'Asia/Damascus',
                ],
                'reminders' => [
                    'useDefault' => false,
                    'overrides' => [
                        ['method' => 'popup', 'minutes' => 1440],
                        ['method' => 'popup', 'minutes' => 60],
                        ['method' => 'popup', 'minutes' => 15],
                    ],
                ],
            ];
            $attendees = [];
            foreach ($attendeeEmails as $email) {
                if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $attendees[] = ['email' => $email];
                }
            }
            if ($attendees !== []) {
                $payload['attendees'] = $attendees;
            }
            if (filled($colorId)) {
                $payload['colorId'] = $colorId;
            }
            if (filled($bookingKey)) {
                $payload['extendedProperties'] = [
                    'private' => [
                        'hoc_booking' => $bookingKey,
                    ],
                ];
            }
            $query = $attendees !== [] ? '?sendUpdates=all' : '';

            $response = Http::withToken($token)
                ->timeout(20)
                ->acceptJson()
                ->asJson()
                ->post(self::API.'/calendars/'.$calendarId.'/events'.$query, $payload);

            if (! $response->successful()) {
                Log::warning('Google Calendar shoot failed.', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $eventId = $response->json('id');

            return filled($eventId) ? (string) $eventId : null;
        } catch (Throwable $exception) {
            Log::warning('Google Calendar shoot exception.', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return list<array{id: string, booking_id: string, start: string, url: string|null}>
     */
    public function listShootEvents(CarbonInterface $from, CarbonInterface $to): array
    {
        if (! $this->configured()) {
            return [];
        }

        $token = $this->auth->accessToken();
        if ($token === null) {
            return [];
        }

        try {
            $calendarId = rawurlencode((string) config('services.google.calendar_id', 'primary'));
            $response = Http::withToken($token)
                ->timeout(20)
                ->acceptJson()
                ->get(self::API.'/calendars/'.$calendarId.'/events', [
                    'timeMin' => $from->copy()->timezone('Asia/Damascus')->toIso8601String(),
                    'timeMax' => $to->copy()->timezone('Asia/Damascus')->toIso8601String(),
                    'singleEvents' => 'true',
                    'orderBy' => 'startTime',
                    'maxResults' => 250,
                    'showDeleted' => 'false',
                ]);

            if (! $response->successful()) {
                Log::warning('Google Calendar list failed.', ['status' => $response->status()]);

                return [];
            }

            $events = [];
            foreach ($response->json('items') ?? [] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $bookingId = $item['extendedProperties']['private']['hoc_booking'] ?? null;
                $start = $item['start']['dateTime'] ?? null;
                $id = $item['id'] ?? null;
                if (! is_string($bookingId) || $bookingId === '' || ! is_string($start) || ! is_string($id) || $id === '') {
                    continue;
                }
                $url = $item['htmlLink'] ?? null;
                $events[] = [
                    'id' => $id,
                    'booking_id' => $bookingId,
                    'start' => $start,
                    'url' => is_string($url) && $url !== '' ? $url : $this->eventUrl($id),
                ];
            }

            return $events;
        } catch (Throwable $exception) {
            Log::warning('Google Calendar list exception.', ['error' => $exception->getMessage()]);

            return [];
        }
    }

    public function updateShoot(
        string $eventId,
        string $summary,
        string $description,
        CarbonInterface $startAt,
        CarbonInterface $endAt,
        string $colorId,
        string $bookingKey,
    ): bool {
        if (! $this->configured()) {
            return false;
        }

        $token = $this->auth->accessToken();
        if ($token === null) {
            return false;
        }

        try {
            $calendarId = rawurlencode((string) config('services.google.calendar_id', 'primary'));
            $start = $startAt->copy()->timezone('Asia/Damascus');
            $end = $endAt->copy()->timezone('Asia/Damascus');
            $response = Http::withToken($token)
                ->timeout(20)
                ->acceptJson()
                ->asJson()
                ->patch(self::API.'/calendars/'.$calendarId.'/events/'.rawurlencode($eventId), [
                    'summary' => $summary,
                    'description' => $description,
                    'colorId' => $colorId,
                    'start' => [
                        'dateTime' => $start->toIso8601String(),
                        'timeZone' => 'Asia/Damascus',
                    ],
                    'end' => [
                        'dateTime' => $end->toIso8601String(),
                        'timeZone' => 'Asia/Damascus',
                    ],
                    'extendedProperties' => [
                        'private' => [
                            'hoc_booking' => $bookingKey,
                        ],
                    ],
                ]);

            if ($response->status() === 404 || $response->status() === 410) {
                return false;
            }

            if (! $response->successful()) {
                Log::warning('Google Calendar update failed.', ['status' => $response->status()]);

                return false;
            }

            return true;
        } catch (Throwable $exception) {
            Log::warning('Google Calendar update exception.', ['error' => $exception->getMessage()]);

            return false;
        }
    }

    public function eventUrl(?string $eventId): ?string
    {
        $calendarId = (string) config('services.google.calendar_id', '');
        if (! filled($eventId) || $calendarId === '') {
            return null;
        }

        $token = rtrim(strtr(base64_encode($eventId.' '.$calendarId), '+/', '-_'), '=');

        return 'https://calendar.google.com/calendar/event?eid='.$token;
    }

    public function openUrl(): ?string
    {
        $calendarId = (string) config('services.google.calendar_id', '');
        if ($calendarId === '' || ! $this->configured()) {
            return null;
        }

        return 'https://calendar.google.com/calendar/u/0/r?cid='.rawurlencode($calendarId);
    }

    public function deleteEvent(?string $eventId): void
    {
        if (! $this->configured() || ! filled($eventId)) {
            return;
        }

        $token = $this->auth->accessToken();
        if ($token === null) {
            return;
        }

        try {
            $calendarId = rawurlencode((string) config('services.google.calendar_id', 'primary'));
            $response = Http::withToken($token)
                ->timeout(20)
                ->delete(self::API.'/calendars/'.$calendarId.'/events/'.rawurlencode((string) $eventId));

            if (! $response->successful() && ! in_array($response->status(), [404, 410], true)) {
                Log::warning('Google Calendar deleteEvent failed.', [
                    'status' => $response->status(),
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('Google Calendar deleteEvent exception.', [
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
