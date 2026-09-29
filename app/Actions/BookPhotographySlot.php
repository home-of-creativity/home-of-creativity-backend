<?php

namespace App\Actions;

use App\Enums\EmployeeProfession;
use App\Models\Client;
use App\Models\Employee;
use App\Models\PhotographyBooking;
use App\Models\ServiceRequest;
use App\Services\GoogleCalendarClient;
use App\Services\TelegramNotifier;
use App\Support\WorkCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class BookPhotographySlot
{
    public const SHOOT_HOURS = 3;

    public const GAP_HOURS = 5;

    public function __construct(
        private WorkCalendar $calendar,
        private NotifyEmployees $notifyEmployees,
        private NotifyClientChannels $notifyClientChannels,
        private GoogleCalendarClient $googleCalendar,
        private TelegramNotifier $telegram,
    ) {}

    /**
     * @return list<array{starts_at: string, label: string}>
     */
    public function freeSlots(): array
    {
        $slots = [];
        $today = now('Asia/Damascus')->toDateString();
        foreach ($this->calendar->upcomingWorkDays(10) as $day) {
            $local = $day->copy()->timezone('Asia/Damascus');
            if ($local->toDateString() === $today) {
                continue;
            }
            $start = $local->copy()->setTime(WorkCalendar::DAY_START_HOUR, 0);
            $dayEnd = $start->copy()->addHours($this->calendar->hoursPerDay());
            for ($cursor = $start->copy(); $cursor->copy()->addHours(self::SHOOT_HOURS)->lte($dayEnd); $cursor->addHour()) {
                if ($this->confirmedConflict($cursor, 0) !== null) {
                    continue;
                }
                $slots[] = [
                    'starts_at' => $cursor->toIso8601String(),
                    'label' => $cursor->format('Y-m-d H:i'),
                ];
            }
        }

        return $slots;
    }

    public function hold(ServiceRequest $request, string $startsAt): PhotographyBooking
    {
        $start = $this->guardStart($startsAt);
        $conflict = $this->confirmedConflict($start, $request->id);
        $booking = PhotographyBooking::query()->updateOrCreate(
            ['request_id' => $request->id, 'starts_at' => $start],
            [
                'employee_id' => $this->photographer()?->id,
                'ends_at' => $start->copy()->addHours(self::SHOOT_HOURS),
                'status' => 'pending_staff',
                'proposed_starts_at' => null,
            ],
        );

        if ($conflict instanceof PhotographyBooking) {
            return $this->offer($booking, $this->offerAfter($conflict->starts_at ?? $start));
        }

        $when = $start->format('Y-m-d H:i');
        $this->notifyEmployees->handle(
            $request,
            EmployeeProfession::Media,
            "موعد تصوير بانتظار موافقتك: الطلب #{$request->number} يوم {$when}. بين موعدين في اليوم نفسه 5 ساعات.",
            [
                ['text' => 'موافقة', 'callback_data' => 'photook:'.$booking->id],
                ['text' => 'وقت آخر', 'callback_data' => 'phototime:'.$booking->id],
            ],
        );

        return $booking;
    }

    public function clientMessage(PhotographyBooking $booking): string
    {
        if ($booking->status === 'needs_client' && $booking->proposed_starts_at !== null) {
            return $this->offerText($booking->proposed_starts_at);
        }

        return 'وصل طلب الموعد. يوافق موظف التصوير، وإذا اقترب من موعد آخر يُعرض عليك وقت يبعد 5 ساعات.';
    }

    public function decide(ServiceRequest $request, bool $accept): PhotographyBooking
    {
        $booking = PhotographyBooking::query()
            ->where('request_id', $request->id)
            ->where('status', 'needs_client')
            ->latest('id')
            ->first();
        if ($booking === null || $booking->proposed_starts_at === null) {
            throw ValidationException::withMessages(['booking' => 'لا يوجد وقت مقترح بانتظار موافقتك.']);
        }

        if (! $accept) {
            $booking->forceFill([
                'status' => 'pending_staff',
                'proposed_starts_at' => null,
            ])->save();
            $this->notifyEmployees->handle(
                $request,
                EmployeeProfession::Media,
                "العميل رفض الوقت المقترح للطلب #{$request->number}. أرسل وقتاً يناسبك.",
                [
                    ['text' => 'وقت آخر', 'callback_data' => 'phototime:'.$booking->id],
                ],
            );

            return $booking;
        }

        return $this->confirm($booking, $booking->proposed_starts_at);
    }

    public function approveSameTime(PhotographyBooking $booking): PhotographyBooking
    {
        $confirmed = $this->confirm(
            $booking,
            $booking->starts_at ?? now('Asia/Damascus'),
        );
        $this->offerNearby($confirmed);

        return $confirmed;
    }

    public function propose(PhotographyBooking $booking, string $startsAt): PhotographyBooking
    {
        $start = $this->guardStart($startsAt);
        if ($this->confirmedConflict($start, $booking->request_id) !== null) {
            throw ValidationException::withMessages([
                'starts_at' => 'هذا الوقت أقرب من 5 ساعات لموعد مثبت. اختر وقتاً أبعد.',
            ]);
        }

        return $this->offer($booking, $start);
    }

    private function offerNearby(PhotographyBooking $confirmed): void
    {
        $start = $confirmed->starts_at;
        if ($start === null) {
            return;
        }
        $start = $this->clock($start);
        $bounds = $this->dayBounds($start);
        $others = PhotographyBooking::query()
            ->with('request.client')
            ->where('id', '!=', $confirmed->id)
            ->whereIn('status', ['pending_staff', 'held', 'needs_client'])
            ->whereBetween('starts_at', [$bounds[0], $bounds[1]])
            ->get();
        foreach ($others as $other) {
            $otherStart = $other->starts_at === null ? null : $this->clock($other->starts_at);
            if ($otherStart === null || abs($start->getTimestamp() - $otherStart->getTimestamp()) >= self::GAP_HOURS * 3600) {
                continue;
            }
            $this->offer($other, $this->offerAfter($start));
        }
    }

    private function offer(PhotographyBooking $booking, Carbon $proposed): PhotographyBooking
    {
        $booking->forceFill([
            'status' => 'needs_client',
            'proposed_starts_at' => $this->stored($proposed),
        ])->save();
        $request = $booking->request ?? ServiceRequest::query()->find($booking->request_id);
        if ($request instanceof ServiceRequest) {
            $this->notifyClientChannels->send($request, $this->offerText($proposed), [
                ['text' => 'يناسبني', 'callback_data' => 'photoyes:'.$request->id],
                ['text' => 'لا يناسبني', 'callback_data' => 'photonno:'.$request->id],
            ]);
        }

        return $booking->fresh() ?? $booking;
    }

    private function confirm(PhotographyBooking $booking, Carbon $start): PhotographyBooking
    {
        $start = $this->clock($start);
        if ($this->confirmedConflict($start, $booking->request_id) !== null) {
            throw ValidationException::withMessages([
                'starts_at' => 'هذا الوقت أقرب من 5 ساعات لموعد مثبت.',
            ]);
        }

        $booking->loadMissing('request.client', 'employee');
        $photographer = $booking->employee ?: $this->photographer();
        $request = $booking->request;
        $emails = array_values(array_filter([
            $photographer?->email,
        ]));
        $eventId = $this->googleCalendar->createShoot(
            'تصوير #'.($request?->number ?? $booking->request_id),
            'موعد تصوير. مدة الحضور 3 ساعات. التذكير قبل يوم وساعة.',
            $start,
            $start->copy()->addHours(self::SHOOT_HOURS),
            $emails,
        );

        $booking->forceFill([
            'status' => 'confirmed',
            'starts_at' => $this->stored($start),
            'ends_at' => $this->stored($start->copy()->addHours(self::SHOOT_HOURS)),
            'proposed_starts_at' => null,
            'employee_id' => $photographer?->id,
            'google_event_id' => $eventId,
            'reminded_at' => null,
        ])->save();

        if ($request instanceof ServiceRequest) {
            $when = $start->format('Y-m-d H:i');
            $this->sendAlarmChoice($request, $booking, $start, $when);
            $this->notifyEmployees->handle(
                $request,
                EmployeeProfession::Media,
                "تذكير: موعد التصوير يوم {$when}.",
            );
        }

        return $booking->fresh() ?? $booking;
    }

    private function sendAlarmChoice(ServiceRequest $request, PhotographyBooking $booking, Carbon $start, string $when): void
    {
        $alarmUrl = URL::temporarySignedRoute('photography.alarm', $start->copy()->addHour(), [
            'booking' => $booking->id,
        ]);
        $text = "تمت الموافقة على موعد التصوير يوم {$when}.";
        $chatId = $request->client?->telegram_user_id;

        try {
            if (is_string($chatId) && Client::isWhatsAppKey($chatId)) {
                $this->telegram->send($chatId, $text."\n".$alarmUrl);

                return;
            }
            if ($this->telegram->canReachClient($chatId)) {
                $this->telegram->sendInlineKeyboard((string) $chatId, $text, [
                    [['text' => 'تسجيل التذكير', 'url' => $alarmUrl]],
                ]);

                return;
            }
            $email = $request->client?->email;
            if (filled($email)) {
                Mail::raw($text."\n".$alarmUrl, function ($message) use ($email): void {
                    $message->to((string) $email)->subject('موعد التصوير');
                });
            }
        } catch (\Throwable) {
            // The booking stays confirmed even if the notice fails.
        }
    }

    private function offerText(Carbon $proposed): string
    {
        return 'لا يمكن الحجز بهذا الوقت. هل يناسبك '.$this->hourLabel($proposed).'؟';
    }

    private function hourLabel(Carbon $time): string
    {
        $local = $this->clock($time);
        $hour = (int) $local->format('G');
        $clock = $local->format((int) $local->format('i') === 0 ? 'g' : 'g:i');
        $suffix = $hour < 12 ? 'صباحاً' : 'مساءً';

        return "الساعة {$clock} {$suffix}";
    }

    private function offerAfter(Carbon $anchor): Carbon
    {
        $anchor = $this->clock($anchor);
        $offer = $anchor->copy()->addHours(self::GAP_HOURS);
        if ($this->calendar->isWorkDay($offer) && $offer->toDateString() === $anchor->toDateString()) {
            return $offer;
        }

        return $this->calendar->nextWorkStart($anchor);
    }

    private function guardStart(string $startsAt): Carbon
    {
        $start = $this->clock(Carbon::parse($startsAt, 'Asia/Damascus'));
        if ($start->toDateString() === now('Asia/Damascus')->toDateString() || $start->lt(now('Asia/Damascus'))) {
            throw ValidationException::withMessages([
                'starts_at' => 'لا يمكن حجز التصوير في نفس اليوم. اختر يوم دوام قادم.',
            ]);
        }
        if (! $this->calendar->isWorkDay($start)) {
            throw ValidationException::withMessages(['starts_at' => 'هذا اليوم ليس يوم دوام.']);
        }

        return $start;
    }

    private function confirmedConflict(Carbon $start, int $exceptRequestId): ?PhotographyBooking
    {
        $bounds = $this->dayBounds($start);
        $rows = PhotographyBooking::query()
            ->where('status', 'confirmed')
            ->when($exceptRequestId > 0, fn ($query) => $query->where('request_id', '!=', $exceptRequestId))
            ->whereBetween('starts_at', [$bounds[0], $bounds[1]])
            ->get();
        foreach ($rows as $row) {
            $other = $row->starts_at === null ? null : $this->clock($row->starts_at);
            if ($other !== null && abs($start->getTimestamp() - $other->getTimestamp()) < self::GAP_HOURS * 3600) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function dayBounds(Carbon $start): array
    {
        $local = $this->clock($start);

        return [
            Carbon::parse($local->copy()->startOfDay()->format('Y-m-d H:i:s'), 'UTC'),
            Carbon::parse($local->copy()->endOfDay()->format('Y-m-d H:i:s'), 'UTC'),
        ];
    }

    private function clock(Carbon $time): Carbon
    {
        return Carbon::parse($time->format('Y-m-d H:i:s'), 'Asia/Damascus');
    }

    private function stored(Carbon $time): Carbon
    {
        return Carbon::parse($this->clock($time)->format('Y-m-d H:i:s'), 'UTC');
    }

    private function photographer(): ?Employee
    {
        return Employee::query()->approved()->where('profession', EmployeeProfession::Media)->orderBy('id')->first();
    }
}
