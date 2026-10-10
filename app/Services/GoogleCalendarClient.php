<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GoogleCalendarClient
{
    private const API = 'https://www.googleapis.com/calendar/v3';

    public function __construct(private GoogleServiceAccount $auth) {}

    public function configured(): bool
    {
        return $this->auth->configured()
            && filled(config('services.google.calendar_id'));
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
