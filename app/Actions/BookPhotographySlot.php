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

    public function __construct(
        private WorkCalendar $calendar,
        private NotifyEmployees $notifyEmployees,
        private GoogleCalendarClient $googleCalendar,
    ) {}

    /**
     * @return list<array{starts_at: string, label: string}>
     */
    /**
     * @return list<array{date: string, label: string}>
     */
    public function bookableDays(int $count = 8): array
    {
        $days = [];
        $cursor = now('Asia/Damascus')->addDays($this->calendar->photographyLeadDays())->startOfDay();
        for ($i = 0; count($days) < $count && $i < 40; $i++) {
            if ($this->calendar->isWorkDay($cursor) && $this->timesOn($cursor) !== []) {
                $days[] = [
                    'date' => $cursor->toDateString(),
                    'label' => $this->dayLabel($cursor),
                ];
            }
            $cursor->addDay();
        }

        return $days;
    }

    /**
     * @return list<array{starts_at: string, label: string}>
     */
    public function timesOn(Carbon $day): array
    {
        [$openMinute, $closeMinute] = $this->calendar->teamWindowMinutes();
        $local = $day->copy()->timezone('Asia/Damascus')->startOfDay();
        $start = $local->copy()->addMinutes($openMinute);
        $end = $local->copy()->addMinutes($closeMinute);
        $slots = [];
        for ($cursor = $start->copy(); $cursor->copy()->addHours(self::SHOOT_HOURS)->lte($end); $cursor->addHour()) {
            if ($this->confirmedConflict($cursor) !== null) {
                continue;
            }
            $slots[] = [
                'starts_at' => $cursor->toIso8601String(),
                'label' => $this->friendlyTime($cursor),
            ];
        }

        return $slots;
    }

    /**
     * @return list<array{starts_at: string, label: string}>
     */
    public function freeSlots(): array
    {
        $slots = [];
        foreach ($this->bookableDays() as $day) {
            foreach ($this->timesOn(Carbon::parse($day['date'], 'Asia/Damascus')) as $slot) {
                $slots[] = $slot;
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

        return 'وصلنا طلب الموعد، ومنرد عليك لتأكيده.';
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
        return 'هاد الوقت محجوز. بيناسبك '.$this->friendlyTime($proposed).'؟';
    }

    private function dayLabel(Carbon $day): string
    {
        $names = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

        return $names[$day->dayOfWeek].' '.$day->format('j/n');
    }

    private function friendlyTime(Carbon $time): string
    {
        $local = $this->clock($time);
        $hour = (int) $local->format('G');
        $clock = $local->format((int) $local->format('i') === 0 ? 'g' : 'g:i');
        $part = match (true) {
            $hour < 12 => 'الصبح',
            $hour < 17 => 'بعد الضهر',
            default => 'المسا',
        };

        return "الساعة {$clock} {$part}";
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
        [$openMinute, $closeMinute] = $this->calendar->teamWindowMinutes();
        $offerMinute = ($offer->hour * 60) + $offer->minute;
        if ($this->calendar->isWorkDay($offer)
            && $offer->toDateString() === $anchor->toDateString()
            && $offerMinute >= $openMinute
            && $offerMinute + (self::SHOOT_HOURS * 60) <= $closeMinute) {
            return $offer;
        }

        $next = $this->calendar->nextWorkStart($anchor);

        return $next->setTime(intdiv($openMinute, 60), $openMinute % 60);
    }

    private function guardStart(string $startsAt): Carbon
    {
        $start = $this->clock(Carbon::parse($startsAt, 'Asia/Damascus'));
        $lead = $this->calendar->photographyLeadDays();
        $earliest = now('Asia/Damascus')->addDays($lead)->startOfDay();
        if ($start->lt($earliest)) {
            throw ValidationException::withMessages([
                'starts_at' => "أقرب موعد للتصوير بعد {$lead} أيام.",
            ]);
        }
        if (! $this->calendar->isWorkDay($start)) {
            throw ValidationException::withMessages(['starts_at' => 'هاد اليوم مو متاح، اختار يوم تاني.']);
        }
        [$openMinute, $closeMinute] = $this->calendar->teamWindowMinutes();
        $minute = ($start->hour * 60) + $start->minute;
        if ($minute < $openMinute || $minute + (self::SHOOT_HOURS * 60) > $closeMinute) {
            throw ValidationException::withMessages(['starts_at' => 'هاد الوقت مو مناسب للتصوير.']);
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
