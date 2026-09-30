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
