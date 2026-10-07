<?php

namespace App\Actions;

use App\Enums\EmployeeProfession;
use App\Models\Employee;
use App\Models\PhotographyBooking;
use App\Models\ServiceRequest;
use App\Services\GoogleCalendarClient;
use App\Support\WorkCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class BookPhotographySlot
{
    public const SHOOT_HOURS = 3;

    public const GAP_HOURS = 5;

    public const LEAD_DAYS = 7;

    public function __construct(
        private WorkCalendar $calendar,
        private NotifyEmployees $notifyEmployees,
        private GoogleCalendarClient $googleCalendar,
    ) {}

    /**
     * @return list<array{starts_at: string, label: string}>
     */
    public function freeSlots(): array
    {
        $slots = [];
        $earliest = now('Asia/Damascus')->addDays(self::LEAD_DAYS)->startOfDay();
        $cursorDay = $earliest->copy();
        $found = 0;
        for ($i = 0; $found < 10 && $i < 24; $i++) {
            if (! $this->calendar->isWorkDay($cursorDay)) {
                $cursorDay->addDay();

                continue;
            }
            $found++;
            $local = $cursorDay->copy();
            $cursorDay->addDay();
            $start = $local->copy()->setTime(WorkCalendar::DAY_START_HOUR, 0);
            $dayEnd = $start->copy()->addHours($this->calendar->hoursPerDay());
            for ($cursor = $start->copy(); $cursor->copy()->addHours(self::SHOOT_HOURS)->lte($dayEnd); $cursor->addHour()) {
                if ($this->confirmedConflict($cursor) !== null) {
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
        $booking = PhotographyBooking::query()->updateOrCreate(
            ['request_id' => $request->id, 'starts_at' => $start],
            [
                'employee_id' => $this->photographer()?->id,
                'ends_at' => $start->copy()->addHours(self::SHOOT_HOURS),
                'status' => 'pending_staff',
                'proposed_starts_at' => null,
            ],
        );

        $conflict = $this->confirmedConflict($start, $booking->id);
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

        return $this->exclusive(function () use ($booking): PhotographyBooking {
            $booking = $booking->fresh() ?? $booking;
            if ($booking->status !== 'needs_client' || $booking->proposed_starts_at === null) {
                throw ValidationException::withMessages(['booking' => 'لا يوجد وقت مقترح بانتظار موافقتك.']);
            }
            $start = $this->clock($booking->proposed_starts_at);
            $conflict = $this->confirmedConflict($start, $booking->id);
            if ($conflict instanceof PhotographyBooking) {
                return $this->offer($booking, $this->offerAfter($this->clock($conflict->starts_at ?? $start)));
            }

            return $this->confirm($booking, $start);
        });
    }

    public function approveSameTime(PhotographyBooking $booking): PhotographyBooking
    {
        return $this->exclusive(function () use ($booking): PhotographyBooking {
            $booking = $booking->fresh() ?? $booking;
            if ($booking->status === 'confirmed') {
                return $booking;
            }
            $start = $this->clock($booking->starts_at ?? now('Asia/Damascus'));
            $conflict = $this->confirmedConflict($start, $booking->id);
            if ($conflict instanceof PhotographyBooking) {
                return $this->offer($booking, $this->offerAfter($this->clock($conflict->starts_at ?? $start)));
            }
            $confirmed = $this->confirm($booking, $start);
            $this->offerNearby($confirmed);

            return $confirmed;
        });
    }

    /**
     * Keep one confirmed shoot inside each 5-hour window and move the rest off the calendar.
     */
    public function separateSameDayClashes(): int
    {
        return (int) $this->exclusive(function (): int {
            $confirmed = PhotographyBooking::query()
                ->where('status', 'confirmed')
                ->whereNotNull('starts_at')
                ->orderBy('id')
                ->get();
            $kept = [];
            $released = 0;
            foreach ($confirmed as $booking) {
                $start = $this->clock($booking->starts_at);
                $clash = null;
                foreach ($kept as $other) {
                    $otherStart = $this->clock($other->starts_at);
                    if ($otherStart->toDateString() !== $start->toDateString()) {
                        continue;
                    }
                    if (abs($start->getTimestamp() - $otherStart->getTimestamp()) < self::GAP_HOURS * 3600) {
                        $clash = $other;
                        break;
                    }
                }
                if (! $clash instanceof PhotographyBooking) {
                    $kept[] = $booking;

                    continue;
                }
                $this->offer($booking, $this->offerAfter($this->clock($clash->starts_at)));
                $released++;
            }

            return $released;
        });
    }

    public function propose(PhotographyBooking $booking, string $startsAt): PhotographyBooking
    {
        return $this->exclusive(function () use ($booking, $startsAt): PhotographyBooking {
            $start = $this->guardStart($startsAt);
            $booking = $booking->fresh() ?? $booking;
            if ($this->confirmedConflict($start, $booking->id) !== null) {
                throw ValidationException::withMessages([
                    'starts_at' => 'هذا الوقت أقرب من 5 ساعات لموعد مثبت. اختر وقتاً أبعد.',
                ]);
            }

            return $this->offer($booking, $start);
        });
    }

    private function offerNearby(PhotographyBooking $confirmed): void
    {
        $start = $confirmed->starts_at;
        if ($start === null) {
            return;
        }
        $start = $this->clock($start);
        $others = PhotographyBooking::query()
            ->with('request.client')
            ->where('id', '!=', $confirmed->id)
            ->whereIn('status', ['pending_staff', 'held', 'needs_client'])
            ->whereNotNull('starts_at')
            ->get();
        foreach ($others as $other) {
            $otherStart = $other->starts_at === null ? null : $this->clock($other->starts_at);
            if ($otherStart === null || $otherStart->toDateString() !== $start->toDateString()) {
                continue;
            }
            if (abs($start->getTimestamp() - $otherStart->getTimestamp()) >= self::GAP_HOURS * 3600) {
                continue;
            }
            $this->offer($other, $this->offerAfter($start));
        }
    }

    private function offer(PhotographyBooking $booking, Carbon $proposed): PhotographyBooking
    {
        if (filled($booking->google_event_id)) {
            $this->googleCalendar->deleteEvent($booking->google_event_id);
        }
        $booking->forceFill([
            'status' => 'needs_client',
            'proposed_starts_at' => $this->stored($proposed),
            'google_event_id' => null,
        ])->save();

        return $booking->fresh() ?? $booking;
    }

    private function confirm(PhotographyBooking $booking, Carbon $start): PhotographyBooking
    {
        $start = $this->clock($start);
        if ($this->confirmedConflict($start, $booking->id) !== null) {
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
            $this->notifyEmployees->handle(
                $request,
                EmployeeProfession::Media,
                "تذكير: موعد التصوير يوم {$when}.",
            );
        }

        return $booking->fresh() ?? $booking;
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
        $earliest = now('Asia/Damascus')->addDays(self::LEAD_DAYS)->startOfDay();
        if ($start->lt($earliest)) {
            throw ValidationException::withMessages([
                'starts_at' => 'أقرب حجز للتصوير بعد أسبوع من اليوم.',
            ]);
        }
        if (! $this->calendar->isWorkDay($start)) {
            throw ValidationException::withMessages(['starts_at' => 'هذا اليوم ليس يوم دوام.']);
        }

        return $start;
    }

    private function confirmedConflict(Carbon $start, ?int $exceptBookingId = null): ?PhotographyBooking
    {
        $start = $this->clock($start);
        $rows = PhotographyBooking::query()
            ->where('status', 'confirmed')
            ->when($exceptBookingId !== null, fn ($query) => $query->where('id', '!=', $exceptBookingId))
            ->whereNotNull('starts_at')
            ->get();
        foreach ($rows as $row) {
            $other = $row->starts_at === null ? null : $this->clock($row->starts_at);
            if ($other === null || $other->toDateString() !== $start->toDateString()) {
                continue;
            }
            if (abs($start->getTimestamp() - $other->getTimestamp()) < self::GAP_HOURS * 3600) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function exclusive(callable $callback): mixed
    {
        return Cache::lock('photography-bookings', 15)->block(10, $callback);
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
