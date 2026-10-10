<?php

namespace App\Actions;

use App\Enums\EmployeeProfession;
use App\Enums\StaffAbility;
use App\Models\Client;
use App\Models\User;
use App\Models\Employee;
use App\Models\PhotographyBooking;
use App\Models\ServiceRequest;
use App\Services\GoogleCalendarClient;
use App\Support\PhotographyActor;
use App\Support\WorkCalendar;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * One photography session per row. The database holds the agreed time; the client
 * message, ClickUp, and Google Calendar follow it after the row is written. Every
 * change runs inside one lock and only moves a row from the status it expects, so a
 * second tap stops without a second charge.
 *
 * Starts are stored as Damascus wall-clock time.
 */
class BookPhotographySlot
{
    public const SHOOT_HOURS = 3;

    public const GAP_HOURS = 5;

    /** A request, an offer, or a move with no answer closes after this many hours. */
    public const REPLY_HOURS = 48;

    /** @var list<callable(): void> */
    private array $effects = [];

    public function __construct(
        private WorkCalendar $calendar,
        private NotifyEmployees $notifyEmployees,
        private NotifyClientChannels $notifyClientChannels,
        private GoogleCalendarClient $googleCalendar,
        private PhotographySessions $sessions,
    ) {}

    // ---------------------------------------------------------------- calendar

    /**
     * @return list<array{date: string, label: string}>
     */
    public function bookableDays(int $count = 8, ?int $ignoreBookingId = null): array
    {
        $days = [];
        $cursor = now('Asia/Damascus')->addDays($this->calendar->photographyLeadDays())->startOfDay();
        for ($i = 0; count($days) < $count && $i < 40; $i++) {
            if ($this->calendar->isWorkDay($cursor) && $this->timesOn($cursor, $ignoreBookingId) !== []) {
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
     * Starts inside the team hours that leave the full shoot before closing and sit
     * five hours from any agreed shoot. A staff list also keeps away from holds.
     *
     * @return list<array{starts_at: string, label: string}>
     */
    public function timesOn(Carbon $day, ?int $ignoreBookingId = null, bool $withHolds = false, bool $allowSoon = false): array
    {
        [$openMinute, $closeMinute] = $this->calendar->teamWindowMinutes();
        $local = Carbon::parse($day->format('Y-m-d'), 'Asia/Damascus')->startOfDay();
        if (! $this->calendar->isWorkDay($local)) {
            return [];
        }
        $start = $local->copy()->addMinutes($openMinute);
        $end = $local->copy()->addMinutes($closeMinute);
        $now = now('Asia/Damascus');
        $slots = [];
        for ($cursor = $start->copy(); $cursor->copy()->addHours(self::SHOOT_HOURS)->lte($end); $cursor->addHour()) {
            if ($allowSoon && $cursor->lte($now)) {
                continue;
            }
            if ($this->conflict($cursor, $ignoreBookingId, $withHolds) !== null) {
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

    // ------------------------------------------------------------- client path

    /**
     * The client picked a time. The row waits for the photographer and holds one
     * session of the balance without charging it.
     */
    public function hold(ServiceRequest $request, string $startsAt, ?PhotographyActor $actor = null): PhotographyBooking
    {
        $actor ??= PhotographyActor::client();

        return $this->run(function () use ($request, $startsAt, $actor): PhotographyBooking {
            $start = $this->guardStart($startsAt, overrideLead: false);
            $request = $request->fresh() ?? $request;
            $this->assertBookable($request);

            $booking = PhotographyBooking::query()->create([
                'request_id' => $request->id,
                'employee_id' => null,
                'starts_at' => $this->stored($start),
                'ends_at' => $this->stored($start->copy()->addHours(self::SHOOT_HOURS)),
                'status' => PhotographyBooking::PENDING_STAFF,
                'waiting_since' => now(),
                'period_key' => $this->sessions->periodKey($request),
            ]);
            $this->log($booking, 'requested', $actor, null);

            $conflict = $this->conflict($start, $booking->id, false);
            if ($conflict instanceof PhotographyBooking) {
                return $this->offerLocked($booking, $this->offerAfter($this->conflictStart($conflict, $start)), PhotographyActor::system());
            }

            $this->effect(function () use ($booking): void {
                $this->sendClient($booking, $this->pendingText($booking));
                $this->askPhotographers($booking);
            });

            return $booking;
        });
    }

    /**
     * The client answers a time the photographer proposed. Yes confirms and charges
     * once; no hands the row back to the photographer and keeps the hold.
     */
    public function clientAnswer(PhotographyBooking $booking, bool $accept): PhotographyBooking
    {
        return $this->run(function () use ($booking, $accept): PhotographyBooking {
            $booking = $this->freshOrFail($booking);
            if ($booking->status !== PhotographyBooking::NEEDS_CLIENT || $booking->proposed_starts_at === null) {
                throw ValidationException::withMessages(['booking' => 'لا يوجد وقت مقترح بانتظار موافقتك.']);
            }
            $actor = PhotographyActor::client();
            if (! $accept) {
                $from = $booking->status;
                $booking->forceFill([
                    'status' => PhotographyBooking::PENDING_STAFF,
                    'proposed_starts_at' => null,
                    'waiting_since' => now(),
                ])->save();
                $this->log($booking, 'client_refused', $actor, $from);
                $this->effect(function () use ($booking): void {
                    $request = $booking->request;
                    $this->notifyEmployees->handle(
                        $request,
                        EmployeeProfession::Media,
                        "العميل رفض الوقت المقترح للطلب #{$request->number} (موعد #{$booking->id}). أرسل وقتاً يناسبك.",
                        [
                            ['text' => 'وقت آخر', 'callback_data' => 'phototime:'.$booking->id],
                            ['text' => 'رفض', 'callback_data' => 'photono:'.$booking->id],
                        ],
                    );
                });

                return $booking;
            }

            $start = $this->clock($booking->proposed_starts_at);
            $conflict = $this->conflict($start, $booking->id, false);
            if ($conflict instanceof PhotographyBooking) {
                return $this->offerLocked($booking, $this->offerAfter($this->conflictStart($conflict, $start)), PhotographyActor::system());
            }

            return $this->confirmLocked($booking, $start, $actor);
        });
    }

    /**
     * An older button that carried the request number instead of the booking id.
     */
    public function decide(ServiceRequest $request, bool $accept): PhotographyBooking
    {
        $booking = PhotographyBooking::query()
            ->where('request_id', $request->id)
            ->where('status', PhotographyBooking::NEEDS_CLIENT)
            ->latest('id')
            ->first();
        if (! $booking instanceof PhotographyBooking) {
            throw ValidationException::withMessages(['booking' => 'لا يوجد وقت مقترح بانتظار موافقتك.']);
        }

        return $this->clientAnswer($booking, $accept);
    }

    /**
     * «خلّي الموعد مثل ما هو»: a move that was not answered yet is dropped and the
     * agreed time stays.
     */
    public function keepOriginal(PhotographyBooking $booking, PhotographyActor $actor): PhotographyBooking
    {
        return $this->run(function () use ($booking, $actor): PhotographyBooking {
            $booking = $this->freshOrFail($booking);
            if ($booking->status !== PhotographyBooking::RESCHEDULING) {
                throw ValidationException::withMessages(['booking' => 'ما في طلب تعديل مفتوح على هالموعد.']);
            }

            return $this->revertLocked($booking, $actor, 'kept');
        });
    }

    // -------------------------------------------------------------- staff path

    /**
     * Accept from the dashboard tab or the staff bot: a waiting request is agreed and
     * charged, a waiting move is applied. Any other status means someone already
     * answered, and nothing changes.
     *
     * @return array{booking: PhotographyBooking, result: string}
     */
    public function accept(PhotographyBooking $booking, PhotographyActor $actor): array
    {
        return $this->run(function () use ($booking, $actor): array {
            $booking = $this->freshOrFail($booking);
            if ($booking->status === PhotographyBooking::RESCHEDULING) {
                return ['booking' => $this->moveLocked($booking, $this->clock($booking->proposed_starts_at), $actor, false), 'result' => 'moved'];
            }
            if ($booking->status !== PhotographyBooking::PENDING_STAFF) {
                return ['booking' => $booking, 'result' => 'already'];
            }
            $request = $booking->request;
            if (! $request instanceof ServiceRequest || ! $this->sessions->isPaidOpen($request)) {
                return ['booking' => $this->closeLocked($booking, PhotographyBooking::EXPIRED, $actor, 'request_closed'), 'result' => 'closed'];
            }
            $start = $this->clock($booking->starts_at);
            $conflict = $this->conflict($start, $booking->id, false);
            if ($conflict instanceof PhotographyBooking) {
                $offered = $this->offerLocked($booking, $this->offerAfter($this->conflictStart($conflict, $start)), $actor);

                return ['booking' => $offered, 'result' => 'offered'];
            }

            return ['booking' => $this->confirmLocked($booking, $start, $actor), 'result' => 'confirmed'];
        });
    }

    /** Kept for the staff bot route and older callers. */
    public function approveSameTime(PhotographyBooking $booking, ?PhotographyActor $actor = null): PhotographyBooking
    {
        return $this->accept($booking, $actor ?? PhotographyActor::system())['booking'];
    }

    /**
     * The photographer refuses: a waiting request or offer closes and frees its
     * hold, a waiting move returns to the agreed time.
     *
     * @return array{booking: PhotographyBooking, result: string}
     */
    public function decline(PhotographyBooking $booking, PhotographyActor $actor): array
    {
        return $this->run(function () use ($booking, $actor): array {
            $booking = $this->freshOrFail($booking);
            if ($booking->status === PhotographyBooking::RESCHEDULING) {
                return ['booking' => $this->revertLocked($booking, $actor, 'move_declined'), 'result' => 'kept'];
            }
            if (! in_array($booking->status, PhotographyBooking::HOLDS, true)) {
                return ['booking' => $booking, 'result' => 'already'];
            }

            return ['booking' => $this->closeLocked($booking, PhotographyBooking::DECLINED, $actor, 'declined'), 'result' => 'declined'];
        });
    }

    /**
     * The photographer offers another time. The client gets it with two buttons that
     * carry this row's id.
     */
    public function propose(PhotographyBooking $booking, string $startsAt, ?PhotographyActor $actor = null, bool $overrideLead = false): PhotographyBooking
    {
        $actor ??= PhotographyActor::system();

        return $this->run(function () use ($booking, $startsAt, $actor, $overrideLead): PhotographyBooking {
            $booking = $this->freshOrFail($booking);
            if (! in_array($booking->status, PhotographyBooking::HOLDS, true)) {
                throw ValidationException::withMessages([
                    'booking' => 'هذا الموعد لم يعد بانتظار وقت جديد. للموعد المثبت استعمل تعديل الموعد.',
                ]);
            }
            $start = $this->guardStart($startsAt, $overrideLead);
            if ($this->conflict($start, $booking->id, true) !== null) {
                throw ValidationException::withMessages([
                    'starts_at' => 'هذا الوقت أقرب من 5 ساعات لموعد مثبت أو محجوز. اختر وقتاً أبعد.',
                ]);
            }
            if ($actor->employee instanceof Employee) {
                $booking->employee_id = $actor->employee->id;
            }

            return $this->offerLocked($booking, $start, $actor);
        });
    }

    /**
     * Moves the same row. Before the agreement the waiting time is replaced. After it,
     * the agreed time stays held until the photographer answers, unless staff apply it
     * at once with `$direct`.
     */
    public function reschedule(
        PhotographyBooking $booking,
        string $startsAt,
        PhotographyActor $actor,
        bool $direct = false,
        bool $overrideLead = false,
    ): PhotographyBooking {
        return $this->run(function () use ($booking, $startsAt, $actor, $direct, $overrideLead): PhotographyBooking {
            $booking = $this->freshOrFail($booking);
            $direct = $direct && $actor->isStaff();
            $overrideLead = $overrideLead && $actor->isStaff();
            $start = $this->guardStart($startsAt, $overrideLead);
            if ($this->conflict($start, $booking->id, $direct || $overrideLead) !== null) {
                throw ValidationException::withMessages([
                    'starts_at' => $direct || $overrideLead
                        ? 'هذا الوقت أقرب من 5 ساعات لموعد مثبت أو لخانة معلقة لعميل آخر.'
                        : 'هالوقت قريب من موعد تاني. اختار وقت أبعد.',
                ]);
            }

            if (in_array($booking->status, PhotographyBooking::HOLDS, true)) {
                if ($direct) {
                    if ($overrideLead) {
                        $booking->lead_override_by = $actor->user?->id ?? 0;
                    }

                    return $this->confirmLocked($booking, $start, $actor);
                }
                $from = $booking->status;
                $booking->forceFill([
                    'starts_at' => $this->stored($start),
                    'ends_at' => $this->stored($start->copy()->addHours(self::SHOOT_HOURS)),
                    'proposed_starts_at' => null,
                    'status' => PhotographyBooking::PENDING_STAFF,
                    'waiting_since' => now(),
                ])->save();
                $this->log($booking, 'repicked', $actor, $from);
                $this->effect(function () use ($booking): void {
                    $this->sendClient($booking, $this->pendingText($booking));
                    $this->askPhotographers($booking);
                });

                return $booking;
            }

            if (! in_array($booking->status, [PhotographyBooking::CONFIRMED, PhotographyBooking::RESCHEDULING], true)) {
                throw ValidationException::withMessages(['booking' => 'هالموعد ما عاد ينفع يتعدل.']);
            }
            $this->assertBeforeShootDay($booking);
            if ($direct) {
                if ($overrideLead) {
                    $booking->lead_override_by = $actor->user?->id ?? 0;
                }

                return $this->moveLocked($booking, $start, $actor, true);
            }

            $previous = $booking->status === PhotographyBooking::RESCHEDULING && $booking->proposed_starts_at !== null
                ? $this->clock($booking->proposed_starts_at)
                : null;
            $from = $booking->status;
            $booking->forceFill([
                'status' => PhotographyBooking::RESCHEDULING,
                'proposed_starts_at' => $this->stored($start),
                'waiting_since' => now(),
            ])->save();
            $this->log($booking, $previous === null ? 'move_requested' : 'move_replaced', $actor, $from);
            $this->effect(function () use ($booking, $previous): void {
                $this->sendClient($booking, $this->moveRequestedText($booking, $previous));
                $this->askPhotographers($booking);
            });

            return $booking;
        });
    }

    /**
     * Staff cancel. An agreed session returns to the balance only before the shoot day
     * starts in Damascus; after that it stays used.
     */
    public function cancel(PhotographyBooking $booking, PhotographyActor $actor): PhotographyBooking
    {
        return $this->run(function () use ($booking, $actor): PhotographyBooking {
            $booking = $this->freshOrFail($booking);
            if (in_array($booking->status, PhotographyBooking::HOLDS, true)) {
                return $this->closeLocked($booking, PhotographyBooking::CANCELLED, $actor, 'cancelled');
            }
            if (! in_array($booking->status, [PhotographyBooking::CONFIRMED, PhotographyBooking::RESCHEDULING], true)) {
                throw ValidationException::withMessages(['booking' => 'هذا الموعد مغلق.']);
            }
            $this->assertBeforeShootDay($booking, 'بدأ يوم الموعد بتوقيت دمشق. الجلسة مستهلكة ولا تُلغى.');
            $from = $booking->status;
            $refunded = $this->refundLocked($booking);
            $eventId = $booking->google_event_id;
            $booking->forceFill([
                'status' => PhotographyBooking::CANCELLED,
                'proposed_starts_at' => null,
                'waiting_since' => null,
                'google_event_id' => null,
            ])->save();
            $this->log($booking, 'cancelled', $actor, $from, $refunded ? 'رُدّت الجلسة إلى الرصيد' : null);
            $this->effect(function () use ($booking, $eventId, $refunded): void {
                $this->googleCalendar->deleteEvent($eventId);
                $this->clickUpClose($booking);
                $request = $booking->request;
                $this->sendClient($booking, $this->say(
                    $request?->client,
                    'ألغينا موعد التصوير '.$this->when($booking->starts_at).' ('.$this->ref($request).').'
                        .($refunded ? ' رجعت الجلسة لرصيدك، '.$this->balanceLine($request).'.' : ''),
                    'Your shoot on '.$this->whenEn($booking->starts_at).' ('.$this->ref($request).') is cancelled.'
                        .($refunded ? ' The session is back in your balance.' : ''),
                ));
                $this->notifyEmployees->handle(
                    $request,
                    EmployeeProfession::Media,
                    "أُلغي موعد التصوير #{$booking->id} للطلب #{$request->number} يوم ".$this->when($booking->starts_at).'.',
                );
                $this->sessions->settle($request);
            });

            return $booking;
        });
    }

    /** After the three hours: the shoot happened. The session stays used and the ClickUp task closes. */
    public function markDone(PhotographyBooking $booking, PhotographyActor $actor): PhotographyBooking
    {
        return $this->finish($booking, $actor, PhotographyBooking::DONE);
    }

    /** After the three hours: the client did not come. The session stays used until staff return it. */
    public function markNoShow(PhotographyBooking $booking, PhotographyActor $actor): PhotographyBooking
    {
        return $this->finish($booking, $actor, PhotographyBooking::NOSHOW);
    }

    /** Returns a no-show session to the balance, while its period is still the open one. */
    public function refundNoShow(PhotographyBooking $booking, PhotographyActor $actor): PhotographyBooking
    {
        return $this->run(function () use ($booking, $actor): PhotographyBooking {
            $booking = $this->freshOrFail($booking);
            if ($booking->status !== PhotographyBooking::NOSHOW || ! $booking->isCharged()) {
                throw ValidationException::withMessages(['booking' => 'الرد متاح فقط لموعد «لم يحضر» ما زالت جلسته مخصومة.']);
            }
            $request = $booking->request;
            if ($request instanceof ServiceRequest && (string) $booking->period_key !== '' && $booking->period_key !== $this->sessions->periodKey($request)) {
                throw ValidationException::withMessages(['booking' => 'فترة هذا الموعد أُغلقت. لا رد بعد بداية فترة جديدة.']);
            }
            $this->refundLocked($booking);
            $this->log($booking, 'noshow_refunded', $actor, $booking->status, 'رُدّت الجلسة إلى الرصيد');
            $this->effect(function () use ($booking): void {
                $request = $booking->request;
                $this->sendClient($booking, $this->say(
                    $request?->client,
                    'رجعنا جلسة التصوير لرصيدك ('.$this->ref($request).'). '.$this->balanceLine($request).'.',
                    'The photography session is back in your balance ('.$this->ref($request).').',
                ));
            });

            return $booking;
        });
    }

    /**
     * New booking from the dashboard, through the same rules as the chat. Staff may
     * agree it at once and may go inside the waiting days, never on another client's
     * held time.
     */
    public function book(
        ServiceRequest $request,
        string $startsAt,
        PhotographyActor $actor,
        bool $confirm,
        bool $overrideLead = false,
    ): PhotographyBooking {
        if (! $confirm && ! $overrideLead) {
            return $this->hold($request, $startsAt, $actor);
        }

        return $this->run(function () use ($request, $startsAt, $actor, $confirm, $overrideLead): PhotographyBooking {
            $start = $this->guardStart($startsAt, $overrideLead);
            $request = $request->fresh() ?? $request;
            $this->assertBookable($request);
            if ($this->conflict($start, null, true) !== null) {
                throw ValidationException::withMessages([
                    'starts_at' => 'هذا الوقت أقرب من 5 ساعات لموعد مثبت أو لخانة معلقة لعميل آخر.',
                ]);
            }
            $booking = PhotographyBooking::query()->create([
                'request_id' => $request->id,
                'employee_id' => $actor->employee?->id,
                'starts_at' => $this->stored($start),
                'ends_at' => $this->stored($start->copy()->addHours(self::SHOOT_HOURS)),
                'status' => PhotographyBooking::PENDING_STAFF,
                'waiting_since' => now(),
                'period_key' => $this->sessions->periodKey($request),
                'lead_override_by' => $overrideLead ? ($actor->user?->id ?? 0) : null,
            ]);
            $this->log($booking, 'booked_by_staff', $actor, null);
            if ($confirm) {
                return $this->confirmLocked($booking, $start, $actor);
            }
            $this->effect(function () use ($booking): void {
                $this->sendClient($booking, $this->pendingText($booking));
                $this->askPhotographers($booking);
            });

            return $booking;
        });
    }

    // ------------------------------------------------------------ follow-ups

    /** Sends again the message the client should have for this row's status. */
    public function resendClient(PhotographyBooking $booking): PhotographyBooking
    {
        $booking = $this->freshOrFail($booking);
        match ($booking->status) {
            PhotographyBooking::NEEDS_CLIENT => $this->sendOffer($booking),
            PhotographyBooking::RESCHEDULING => $this->sendClient($booking, $this->moveRequestedText($booking, null)),
            PhotographyBooking::CONFIRMED => $this->sendClient($booking, $this->confirmedText($booking)),
            PhotographyBooking::PENDING_STAFF => $this->sendClient($booking, $this->pendingText($booking)),
            default => throw ValidationException::withMessages(['booking' => 'هذا الموعد مغلق ولا رسالة له.']),
        };

        return $booking->fresh() ?? $booking;
    }

    public function retryCalendar(PhotographyBooking $booking): PhotographyBooking
    {
        $booking = $this->freshOrFail($booking);
        if ($booking->status !== PhotographyBooking::CONFIRMED) {
            throw ValidationException::withMessages(['booking' => 'التقويم للموعد المثبت فقط.']);
        }
        if (filled($booking->google_event_id)) {
            $this->googleCalendar->deleteEvent($booking->google_event_id);
            $booking->forceFill(['google_event_id' => null])->save();
        }
        $this->calendarCreate($booking);

        return $booking->fresh() ?? $booking;
    }

    public function retryClickUp(PhotographyBooking $booking): PhotographyBooking
    {
        $booking = $this->freshOrFail($booking);
        if (! in_array($booking->status, [PhotographyBooking::CONFIRMED, PhotographyBooking::RESCHEDULING], true)) {
            throw ValidationException::withMessages(['booking' => 'مهمة ClickUp للموعد المثبت فقط.']);
        }
        $this->clickUpSync($booking);

        return $booking->fresh() ?? $booking;
    }

    /**
     * Rows with no answer for 48 hours close without a charge; a move returns to the
     * agreed time. Holds on a request that is no longer paid close as well.
     */
    public function expireStale(): int
    {
        $closed = 0;
        $limit = now()->subHours(self::REPLY_HOURS);
        $rows = PhotographyBooking::query()
            ->with('request')
            ->whereIn('status', PhotographyBooking::OPEN)
            ->get();
        foreach ($rows as $row) {
            $request = $row->request;
            $stale = $row->waiting_since !== null && $row->waiting_since->lte($limit);
            $ineligible = in_array($row->status, PhotographyBooking::HOLDS, true)
                && (! $request instanceof ServiceRequest || ! $this->sessions->isPaidOpen($request));
            if (! $stale && ! $ineligible && $request instanceof ServiceRequest && $row->waiting_since !== null && $row->waiting_since->lte(now()->subHours(self::REPLY_HOURS - 1))) {
                if (Cache::add('hoc:photo-late:'.$row->id, 1, now()->addHours(3))) {
                    $this->notifyEmployees->handle(
                        $request,
                        EmployeeProfession::Media,
                        "موعد تصوير متأخر #{$row->id} للطلب #{$request->number}. ينتهي خلال ساعة إن لم يُرد عليه.",
                    );
                }

                continue;
            }
            if (! $stale && ! $ineligible) {
                continue;
            }
            try {
                $this->run(function () use ($row, $ineligible): void {
                    $row = $row->fresh() ?? $row;
                    if (! in_array($row->status, PhotographyBooking::OPEN, true)) {
                        return;
                    }
                    if ($row->status === PhotographyBooking::RESCHEDULING) {
                        $this->revertLocked($row, PhotographyActor::system(), 'move_expired');

                        return;
                    }
                    $this->closeLocked($row, PhotographyBooking::EXPIRED, PhotographyActor::system(), $ineligible ? 'request_closed' : 'expired', notify: ! $ineligible);
                });
                $closed++;
            } catch (Throwable $exception) {
                Log::warning('Photography expiry skipped a row.', ['booking' => $row->id, 'error' => $exception->getMessage()]);
            }
        }

        return $closed;
    }

    /** After the three hours, ask Media to mark the shoot done or a no-show. The row stays confirmed. */
    public function remindUnmarked(): int
    {
        $now = now('Asia/Damascus')->format('Y-m-d H:i:s');
        $rows = PhotographyBooking::query()
            ->with('request')
            ->where('status', PhotographyBooking::CONFIRMED)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', $now)
            ->get();
        $sent = 0;
        foreach ($rows as $row) {
            if (! Cache::add('hoc:photo-unmarked:'.$row->id, 1, now()->addDays(7))) {
                continue;
            }
            $request = $row->request;
            if (! $request instanceof ServiceRequest) {
                continue;
            }
            $this->notifyEmployees->handle(
                $request,
                EmployeeProfession::Media,
                "جلسة التصوير #{$row->id} للطلب #{$request->number} خلصت. علّمها تم أو لم يحضر من تاب التصوير. الخصم ما بيرجع إلا بزر لم يحضر.",
            );
            $sent++;
        }

        return $sent;
    }

    /**
     * The day before an agreed shoot. A time agreed or moved on the reminder day itself
     * already carries a fresh message, so it is not reminded again.
     */
    public function sendDayBeforeReminders(): int
    {
        $now = now('Asia/Damascus');
        if ($now->hour < 10) {
            return 0;
        }
        $tomorrow = $now->copy()->addDay()->startOfDay();
        $rows = PhotographyBooking::query()
            ->with(['request.client', 'employee'])
            ->where('status', PhotographyBooking::CONFIRMED)
            ->whereNull('reminded_at')
            ->where('starts_at', '>=', $tomorrow->format('Y-m-d H:i:s'))
            ->where('starts_at', '<', $tomorrow->copy()->addDay()->format('Y-m-d H:i:s'))
            ->get();
        $sent = 0;
        foreach ($rows as $row) {
            if ($row->confirmed_at !== null && $row->confirmed_at->gte($now->copy()->startOfDay())) {
                continue;
            }
            $request = $row->request;
            $this->sendClient($row, $this->say(
                $request?->client,
                'تذكير: موعد التصوير بكرا '.$this->when($row->starts_at).' ('.$this->ref($request).'). الجلسة '.self::SHOOT_HOURS.' ساعات.',
                'Reminder: your shoot is tomorrow, '.$this->whenEn($row->starts_at).' ('.$this->ref($request).').',
            ));
            if ($row->employee instanceof Employee) {
                $this->notifyEmployees->toEmployee($row->employee, 'تذكير: جلسة تصوير بكرا '.$this->when($row->starts_at).' للطلب #'.$request?->number.'.');
            }
            $row->forceFill(['reminded_at' => now()])->save();
            $sent++;
        }

        return $sent;
    }

    /**
     * Repair for rows written before the five-hour rule: the earliest agreed shoot in
     * each window stays, the rest go back to the client as an offer and their charge
     * returns to the balance.
     */
    public function separateSameDayClashes(): int
    {
        return (int) $this->run(function (): int {
            $confirmed = PhotographyBooking::query()
                ->where('status', PhotographyBooking::CONFIRMED)
                ->whereNotNull('starts_at')
                ->orderBy('id')
                ->get();
            $kept = [];
            $released = 0;
            foreach ($confirmed as $booking) {
                $start = $this->clock($booking->starts_at);
                $clash = null;
                foreach ($kept as $other) {
                    if ($this->tooClose($start, $this->clock($other->starts_at))) {
                        $clash = $other;
                        break;
                    }
                }
                if (! $clash instanceof PhotographyBooking) {
                    $kept[] = $booking;

                    continue;
                }
                $this->refundLocked($booking);
                $eventId = $booking->google_event_id;
                $booking->forceFill(['google_event_id' => null])->save();
                $this->effect(fn () => $this->googleCalendar->deleteEvent($eventId));
                $this->offerLocked($booking, $this->offerAfter($this->clock($clash->starts_at)), PhotographyActor::system());
                $released++;
            }

            return $released;
        });
    }

    // ------------------------------------------------------------- read models

    /**
     * The client's rows that still matter in the chat: waiting ones and agreed ones
     * whose day has not passed.
     *
     * @return Collection<int, PhotographyBooking>
     */
    public function clientBookings(Client $client): Collection
    {
        $today = now('Asia/Damascus')->startOfDay()->format('Y-m-d H:i:s');

        return PhotographyBooking::query()
            ->with('request.pricingPackage')
            ->whereHas('request', fn ($query) => $query->where('client_id', $client->id))
            ->where(function ($query) use ($today): void {
                $query->whereIn('status', PhotographyBooking::OPEN)
                    ->orWhere(fn ($agreed) => $agreed->where('status', PhotographyBooking::CONFIRMED)->where('starts_at', '>=', $today));
            })
            ->orderBy('starts_at')
            ->get();
    }

    /** The newest offer that waits for this client. */
    public function openOfferFor(Client $client): ?PhotographyBooking
    {
        return $this->clientBookings($client)
            ->where('status', PhotographyBooking::NEEDS_CLIENT)
            ->sortByDesc('id')
            ->first();
    }

    public function clientMessage(PhotographyBooking $booking): string
    {
        $booking = $booking->fresh() ?? $booking;

        return match ($booking->status) {
            PhotographyBooking::NEEDS_CLIENT => $this->offerText($booking),
            PhotographyBooking::CONFIRMED => $this->confirmedText($booking),
            PhotographyBooking::RESCHEDULING => $this->moveRequestedText($booking, null),
            default => $this->pendingText($booking),
        };
    }

    public function statusLabel(PhotographyBooking $booking, ?Client $client = null): string
    {
        return match ($booking->status) {
            PhotographyBooking::PENDING_STAFF => $this->say($client, 'بانتظار المصور', 'waiting for the photographer'),
            PhotographyBooking::NEEDS_CLIENT => $this->say($client, 'بانتظارك', 'waiting for you'),
            PhotographyBooking::RESCHEDULING => $this->say($client, 'مثبت، وطلب التعديل عند المصور', 'agreed, move waiting'),
            PhotographyBooking::CONFIRMED => $this->say($client, 'مثبت', 'agreed'),
            default => $booking->status,
        };
    }

    /** «الأربعاء 14/10 الساعة 9 الصبح» */
    public function when(?Carbon $time): string
    {
        if ($time === null) {
            return '';
        }
        $local = $this->clock($time);

        return $this->dayLabel($local).' '.$this->friendlyTime($local);
    }

    public function dayLabel(Carbon $day): string
    {
        $names = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
        $local = $this->clock($day);

        return $names[$local->dayOfWeek].' '.$local->format('j/n');
    }

    public function ref(?ServiceRequest $request): string
    {
        if (! $request instanceof ServiceRequest) {
            return '';
        }
        $request->loadMissing('pricingPackage');
        $name = trim((string) ($request->pricingPackage?->name_ar ?: $request->pricingPackage?->name_en ?: $request->title));

        return trim($name.' #'.$request->number);
    }

    /** «باقي جلستان من 2» */
    public function balanceLine(?ServiceRequest $request): string
    {
        if (! $request instanceof ServiceRequest) {
            return '';
        }
        $request = $request->fresh() ?? $request;
        $left = $this->sessions->remaining($request);
        $cap = (int) $this->sessions->cap($request);
        $word = match (true) {
            $left === 0 => 'ما باقي جلسات',
            $left === 1 => 'باقي جلسة وحدة',
            $left === 2 => 'باقي جلستان',
            $left <= 10 => "باقي {$left} جلسات",
            default => "باقي {$left} جلسة",
        };

        return $left === 0 ? "{$word} من {$cap}" : "{$word} من {$cap}";
    }

    /** «جلسة 1 من 2» */
    public function sessionLabel(PhotographyBooking $booking): string
    {
        $request = $booking->request;
        $cap = (int) ($request?->photography_sessions ?? 0);
        $number = $booking->session_number ?: ((int) ($request?->photography_sessions_used ?? 0) + 1);

        return "جلسة {$number} من ".max($cap, $number);
    }

    // ---------------------------------------------------------------- internals

    private function confirmLocked(PhotographyBooking $booking, Carbon $start, PhotographyActor $actor): PhotographyBooking
    {
        $start = $this->clock($start);
        if ($this->conflict($start, $booking->id, false) !== null) {
            throw ValidationException::withMessages(['starts_at' => 'هذا الوقت أقرب من 5 ساعات لموعد مثبت.']);
        }
        $from = $booking->status;
        DB::transaction(function () use ($booking, $start, $actor): void {
            $request = ServiceRequest::query()->lockForUpdate()->findOrFail($booking->request_id);
            $chargeNow = ! $booking->isCharged();
            if ($chargeNow) {
                $used = $this->sessions->used($request) + 1;
                $request->forceFill(['photography_sessions_used' => $used])->save();
                $booking->forceFill([
                    'charged_at' => now(),
                    'refunded_at' => null,
                    'session_number' => $used,
                    'period_key' => $this->sessions->periodKey($request),
                ]);
            }
            $booking->forceFill([
                'status' => PhotographyBooking::CONFIRMED,
                'starts_at' => $this->stored($start),
                'ends_at' => $this->stored($start->copy()->addHours(self::SHOOT_HOURS)),
                'proposed_starts_at' => null,
                'employee_id' => $actor->employee?->id ?? $booking->employee_id ?? $this->solePhotographer()?->id,
                'confirmed_at' => now(),
                'waiting_since' => null,
                'reminded_at' => null,
            ])->save();
        });
        $this->log($booking, 'confirmed', $actor, $from, 'خُصمت جلسة');
        $nearby = $this->offerNearbyLocked($booking);

        $this->effect(function () use ($booking): void {
            $booking = $booking->fresh(['request.client', 'employee']) ?? $booking;
            $request = $booking->request;
            $this->sendClient($booking, $this->confirmedText($booking));
            $this->notifyEmployees->handle(
                $request,
                EmployeeProfession::Media,
                "اتفقنا على موعد التصوير للطلب #{$request->number} يوم ".$this->when($booking->starts_at)
                    .'. '.$this->sessionLabel($booking).($booking->employee ? '، المصور '.$booking->employee->name : '').'.',
            );
            $this->clickUpSync($booking);
            $this->calendarCreate($booking);
        });
        foreach ($nearby as $offered) {
            $this->log($offered, 'offered_after_clash', PhotographyActor::system(), PhotographyBooking::PENDING_STAFF);
        }

        return $booking->fresh() ?? $booking;
    }

    /**
     * Applies a new start to an agreed row. No second charge, the reminder starts over,
     * and the accepting photographer becomes the owner.
     */
    private function moveLocked(PhotographyBooking $booking, Carbon $start, PhotographyActor $actor, bool $direct): PhotographyBooking
    {
        $start = $this->clock($start);
        if ($this->conflict($start, $booking->id, $direct) !== null) {
            throw ValidationException::withMessages(['starts_at' => 'الوقت الجديد صار قريباً من موعد مثبت آخر. ارفض التعديل أو اختر وقتاً آخر.']);
        }
        $from = $booking->status;
        $oldStart = $booking->starts_at;
        $oldEvent = $booking->google_event_id;
        $previousEmployee = $booking->employee_id;
        $booking->forceFill([
            'status' => PhotographyBooking::CONFIRMED,
            'starts_at' => $this->stored($start),
            'ends_at' => $this->stored($start->copy()->addHours(self::SHOOT_HOURS)),
            'proposed_starts_at' => null,
            'employee_id' => $actor->employee?->id ?? $booking->employee_id,
            'google_event_id' => null,
            'waiting_since' => null,
            'reminded_at' => null,
            'confirmed_at' => now(),
        ])->save();
        $this->log($booking, 'moved', $actor, $from, 'من '.$this->when($oldStart));

        $this->effect(function () use ($booking, $oldEvent, $oldStart, $previousEmployee): void {
            $booking = $booking->fresh(['request.client', 'employee']) ?? $booking;
            $request = $booking->request;
            // The old event goes first so the photographer never sees two times.
            $this->googleCalendar->deleteEvent($oldEvent);
            $this->sendClient($booking, $this->movedText($booking, $oldStart));
            $this->calendarCreate($booking);
            $this->clickUpSync($booking);
            $this->notifyEmployees->handle(
                $request,
                EmployeeProfession::Media,
                "نُقل موعد التصوير #{$booking->id} للطلب #{$request->number} من ".$this->when($oldStart).' إلى '.$this->when($booking->starts_at).'.',
            );
            if ($previousEmployee !== null && $previousEmployee !== $booking->employee_id) {
                $previous = Employee::query()->find($previousEmployee);
                if ($previous instanceof Employee) {
                    $this->notifyEmployees->toEmployee($previous, "جلسة التصوير #{$booking->id} للطلب #{$request->number} انتقلت منك إلى ".($booking->employee?->name ?? 'مصور آخر').'.');
                }
            }
        });

        return $booking->fresh() ?? $booking;
    }

    private function revertLocked(PhotographyBooking $booking, PhotographyActor $actor, string $action): PhotographyBooking
    {
        $from = $booking->status;
        $booking->forceFill([
            'status' => PhotographyBooking::CONFIRMED,
            'proposed_starts_at' => null,
            'waiting_since' => null,
        ])->save();
        $this->log($booking, $action, $actor, $from);
        $this->effect(function () use ($booking, $action): void {
            $request = $booking->request;
            $this->sendClient($booking, $this->say(
                $request?->client,
                'موعد التصوير ('.$this->ref($request).') بقي بوقته '.$this->when($booking->starts_at).'.',
                'Your shoot ('.$this->ref($request).') stays on '.$this->whenEn($booking->starts_at).'.',
            ));
            if ($action === 'kept' && $request instanceof ServiceRequest) {
                $this->notifyEmployees->handle(
                    $request,
                    EmployeeProfession::Media,
                    "العميل تراجع عن تعديل موعد التصوير #{$booking->id}. الموعد بقي ".$this->when($booking->starts_at).'.',
                );
            }
            if ($request instanceof ServiceRequest) {
                $this->sessions->settle($request);
            }
        });

        return $booking;
    }

    private function closeLocked(PhotographyBooking $booking, string $status, PhotographyActor $actor, string $action, bool $notify = true): PhotographyBooking
    {
        $from = $booking->status;
        $booking->forceFill([
            'status' => $status,
            'waiting_since' => null,
        ])->save();
        $this->log($booking, $action, $actor, $from);
        $this->effect(function () use ($booking, $status, $notify): void {
            $request = $booking->request;
            if ($request instanceof ServiceRequest) {
                $this->sessions->settle($request);
            }
            if (! $notify || ! $request instanceof ServiceRequest) {
                return;
            }
            $time = $booking->proposed_starts_at ?? $booking->starts_at;
            $left = $this->sessions->remaining($request->fresh() ?? $request);
            $again = $left >= 1 ? $this->say($request->client, ' فيك تختار وقتاً غيره، ابعت «موعد تصوير».', ' You can pick another time: send “photo booking”.') : '';
            $text = match ($status) {
                PhotographyBooking::EXPIRED => $this->say($request->client, 'ما ثبتنا موعد '.$this->when($time).' ('.$this->ref($request).').', 'We did not confirm '.$this->whenEn($time).' ('.$this->ref($request).').').$again,
                PhotographyBooking::DECLINED => $this->say($request->client, 'ما قدرنا نثبت موعد '.$this->when($time).' ('.$this->ref($request).').', 'We could not confirm '.$this->whenEn($time).' ('.$this->ref($request).').').$again,
                default => $this->say($request->client, 'ألغينا طلب موعد التصوير '.$this->when($time).' ('.$this->ref($request).').', 'The shoot request for '.$this->whenEn($time).' ('.$this->ref($request).') is cancelled.'),
            };
            $this->sendClient($booking, $text);
        });

        return $booking;
    }

    private function finish(PhotographyBooking $booking, PhotographyActor $actor, string $status): PhotographyBooking
    {
        return $this->run(function () use ($booking, $actor, $status): PhotographyBooking {
            $booking = $this->freshOrFail($booking);
            if ($booking->status !== PhotographyBooking::CONFIRMED) {
                throw ValidationException::withMessages(['booking' => 'يُعلَّم الموعد المثبت فقط.']);
            }
            if ($booking->ends_at !== null && $this->clock($booking->ends_at)->gt(now('Asia/Damascus'))) {
                throw ValidationException::withMessages(['booking' => 'يُعلَّم بعد نهاية الساعات الثلاث.']);
            }
            $from = $booking->status;
            $booking->forceFill(['status' => $status])->save();
            $this->log($booking, $status, $actor, $from);
            if ($status === PhotographyBooking::DONE) {
                $this->effect(fn () => $this->clickUpClose($booking));
            }

            return $booking;
        });
    }

    /** Returns a charged session to the balance. */
    private function refundLocked(PhotographyBooking $booking): bool
    {
        if (! $booking->isCharged()) {
            return false;
        }
        DB::transaction(function () use ($booking): void {
            $request = ServiceRequest::query()->lockForUpdate()->find($booking->request_id);
            if ($request instanceof ServiceRequest && (string) $booking->period_key === $this->sessions->periodKey($request)) {
                $request->forceFill(['photography_sessions_used' => max(0, $this->sessions->used($request) - 1)])->save();
            }
            $booking->forceFill(['refunded_at' => now()])->save();
        });

        return true;
    }

    private function offerLocked(PhotographyBooking $booking, Carbon $proposed, PhotographyActor $actor): PhotographyBooking
    {
        $from = $booking->status;
        $booking->forceFill([
            'status' => PhotographyBooking::NEEDS_CLIENT,
            'proposed_starts_at' => $this->stored($proposed),
            'waiting_since' => now(),
        ])->save();
        $this->log($booking, 'offered', $actor, $from);
        $this->effect(fn () => $this->sendOffer($booking));

        return $booking;
    }

    /**
     * Other holds on the same day inside five hours of the agreed start get the next
     * free time instead.
     *
     * @return list<PhotographyBooking>
     */
    private function offerNearbyLocked(PhotographyBooking $confirmed): array
    {
        $start = $this->clock($confirmed->starts_at);
        $others = PhotographyBooking::query()
            ->where('id', '!=', $confirmed->id)
            ->whereIn('status', PhotographyBooking::HOLDS)
            ->get();
        $offered = [];
        foreach ($others as $other) {
            $held = $other->status === PhotographyBooking::NEEDS_CLIENT ? $other->proposed_starts_at : $other->starts_at;
            if ($held === null || ! $this->tooClose($start, $this->clock($held))) {
                continue;
            }
            $offered[] = $this->offerLocked($other, $this->offerAfter($start), PhotographyActor::system());
        }

        return $offered;
    }

    private function sendOffer(PhotographyBooking $booking): void
    {
        $booking = $booking->fresh(['request.client']) ?? $booking;
        $client = $booking->request?->client;
        $this->sendClient($booking, $this->offerText($booking), [
            ['text' => $this->say($client, 'يناسبني', 'Works for me'), 'callback_data' => 'photoyes:'.$booking->id],
            ['text' => $this->say($client, 'لا يناسبني', 'Does not work'), 'callback_data' => 'photonno:'.$booking->id],
        ]);
    }

    /**
     * @param  list<array{text: string, callback_data: string}>|null  $buttons
     */
    private function sendClient(PhotographyBooking $booking, string $text, ?array $buttons = null): bool
    {
        $request = $booking->request ?? ServiceRequest::query()->find($booking->request_id);
        if (! $request instanceof ServiceRequest) {
            return false;
        }
        $sent = $this->notifyClientChannels->send($request, $text, $buttons);
        $booking->forceFill([
            'client_notified_at' => $sent ? now() : $booking->client_notified_at,
            'client_notify_failed' => ! $sent,
        ])->save();

        return $sent;
    }

    private function askPhotographers(PhotographyBooking $booking): void
    {
        $booking = $booking->fresh(['request.client']) ?? $booking;
        $request = $booking->request;
        $client = $request?->client?->name ?: 'العميل';
        if ($booking->status === PhotographyBooking::RESCHEDULING) {
            $this->notifyEmployees->handle(
                $request,
                EmployeeProfession::Media,
                "طلب تعديل موعد التصوير #{$booking->id} ({$client} — ".$this->ref($request).'): من '.$this->when($booking->starts_at).' إلى '.$this->when($booking->proposed_starts_at).'. الوقت القديم محجوز لحين ردك.',
                [
                    ['text' => 'قبول', 'callback_data' => 'photook:'.$booking->id],
                    ['text' => 'رفض', 'callback_data' => 'photono:'.$booking->id],
                ],
            );

            return;
        }
        $this->notifyEmployees->handle(
            $request,
            EmployeeProfession::Media,
            "موعد تصوير بانتظار القبول #{$booking->id}: {$client} — ".$this->ref($request).' يوم '.$this->when($booking->starts_at)
                .'. الحضور '.self::SHOOT_HOURS.' ساعات، وبين موعدين '.self::GAP_HOURS.' ساعات.',
            [
                ['text' => 'قبول', 'callback_data' => 'photook:'.$booking->id],
                ['text' => 'وقت آخر', 'callback_data' => 'phototime:'.$booking->id],
                ['text' => 'رفض', 'callback_data' => 'photono:'.$booking->id],
            ],
        );
    }

    /**
     * Applies a time that was moved in Google Calendar, then writes every open or
     * agreed shoot back onto that calendar. A move by someone with the full photography ability
     * is applied directly. Anyone else turns it into a client proposal.
     */
    public function syncGoogleCalendar(User $user): void
    {
        if (! $this->googleCalendar->configured()) {
            return;
        }

        $direct = $user->canAbility(StaffAbility::OpsPhotographyAll);
        $actor = PhotographyActor::user($user);
        foreach ($this->googleCalendar->listShootEvents(now('Asia/Damascus')->subDay(), now('Asia/Damascus')->addDays(70)) as $event) {
            $booking = PhotographyBooking::query()->find($event['booking_id']);
            if (! $booking instanceof PhotographyBooking || $booking->google_event_id !== $event['id'] || $booking->starts_at === null) {
                continue;
            }
            $remote = Carbon::parse($event['start'])->timezone('Asia/Damascus');
            $current = $this->clock($booking->starts_at);
            if ($remote->format('Y-m-d H:i') === $current->format('Y-m-d H:i')) {
                continue;
            }
            try {
                $this->reschedule($booking, $remote->format('Y-m-d H:i:s'), $actor, $direct, $direct);
            } catch (ValidationException $exception) {
                $fresh = $booking->fresh(['request.client', 'employee']);
                if ($fresh instanceof PhotographyBooking) {
                    $this->mirrorCalendar($fresh);
                }
                Log::info('Google Calendar move was not applied.', [
                    'booking_id' => $booking->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $rows = PhotographyBooking::query()
            ->with(['request.client', 'employee'])
            ->whereIn('status', [...PhotographyBooking::OPEN, PhotographyBooking::CONFIRMED])
            ->whereNotNull('starts_at')
            ->where('starts_at', '>=', now('Asia/Damascus')->subDay()->format('Y-m-d H:i:s'))
            ->orderBy('id')
            ->limit(200)
            ->get();

        foreach ($rows as $booking) {
            $this->mirrorCalendar($booking);
        }
    }

    public function calendarLink(?string $eventId): ?string
    {
        return $this->googleCalendar->eventUrl($eventId);
    }

    public function calendarHome(): ?string
    {
        return $this->googleCalendar->openUrl();
    }

    private function mirrorCalendar(PhotographyBooking $booking): void
    {
        $booking = $booking->fresh(['request.client', 'employee']) ?? $booking;
        if ($booking->starts_at === null || ! $this->googleCalendar->configured()) {
            return;
        }
        if (! in_array($booking->status, [...PhotographyBooking::OPEN, PhotographyBooking::CONFIRMED], true)) {
            return;
        }
        $start = $this->clock($booking->starts_at);
        $end = $start->copy()->addHours(self::SHOOT_HOURS);
        if (filled($booking->google_event_id) && $this->googleCalendar->updateShoot(
            (string) $booking->google_event_id,
            $this->calendarSummary($booking),
            $this->calendarDescription($booking),
            $start,
            $end,
            $this->calendarColor($booking->status),
            (string) $booking->id,
        )) {
            if ($booking->calendar_failed_at !== null) {
                $booking->forceFill(['calendar_failed_at' => null])->save();
            }

            return;
        }

        $eventId = $this->googleCalendar->createShoot(
            $this->calendarSummary($booking),
            $this->calendarDescription($booking),
            $start,
            $end,
            $booking->status === PhotographyBooking::CONFIRMED ? array_values(array_filter([$booking->employee?->email])) : [],
            $this->calendarColor($booking->status),
            (string) $booking->id,
        );
        $booking->forceFill([
            'google_event_id' => $eventId,
            'calendar_failed_at' => $eventId === null ? now() : null,
        ])->save();
    }

    private function calendarSummary(PhotographyBooking $booking): string
    {
        $request = $booking->request;
        $label = match ($booking->status) {
            PhotographyBooking::PENDING_STAFF => 'انتظار المصور',
            PhotographyBooking::NEEDS_CLIENT => 'انتظار العميل',
            PhotographyBooking::RESCHEDULING => 'تعديل الموعد',
            default => 'موعد تصوير',
        };

        return $label.' · '.($request?->client?->name ?: 'العميل').' · #'.($request?->number ?? $booking->request_id);
    }

    private function calendarDescription(PhotographyBooking $booking): string
    {
        $request = $booking->request;

        return implode("\n", array_filter([
            $this->calendarSummary($booking),
            $this->sessionLabel($booking),
            $this->ref($request),
            $request?->client?->phone,
            $booking->employee?->name ? 'المصور: '.$booking->employee->name : null,
            'مدة الحضور '.self::SHOOT_HOURS.' ساعات.',
            'تحريك الموعد في جوجل كالندر يحدّث لوحة التصوير.',
        ]));
    }

    private function calendarColor(string $status): string
    {
        return match ($status) {
            PhotographyBooking::PENDING_STAFF => '6',
            PhotographyBooking::NEEDS_CLIENT => '5',
            PhotographyBooking::RESCHEDULING => '3',
            default => '7',
        };
    }

    private function calendarCreate(PhotographyBooking $booking): void
    {
        $booking = $booking->fresh(['request.client', 'employee']) ?? $booking;
        if ($booking->status !== PhotographyBooking::CONFIRMED || filled($booking->google_event_id)) {
            return;
        }
        if (! $this->googleCalendar->configured() || $booking->starts_at === null) {
            return;
        }
        $start = $this->clock($booking->starts_at);
        $eventId = $this->googleCalendar->createShoot(
            $this->calendarSummary($booking),
            $this->calendarDescription($booking),
            $start,
            $start->copy()->addHours(self::SHOOT_HOURS),
            array_values(array_filter([$booking->employee?->email])),
            $this->calendarColor($booking->status),
            (string) $booking->id,
        );
        $booking->forceFill([
            'google_event_id' => $eventId,
            'calendar_failed_at' => $eventId === null ? now() : null,
        ])->save();
    }

    private function clickUpSync(PhotographyBooking $booking): void
    {
        $booking = $booking->fresh(['request', 'employee']) ?? $booking;
        $request = $booking->request;
        if (! $request instanceof ServiceRequest) {
            return;
        }
        $ok = app(ProvisionClickUpTasks::class)->syncPhotographySession($request, $booking, $this->sessionLabel($booking));
        $booking->forceFill(['clickup_failed_at' => $ok ? null : now()])->save();
    }

    private function clickUpClose(PhotographyBooking $booking): void
    {
        $request = $booking->request;
        if ($request instanceof ServiceRequest) {
            app(ProvisionClickUpTasks::class)->closePhotographySession($request, $booking);
        }
    }

    private function log(PhotographyBooking $booking, string $action, PhotographyActor $actor, ?string $from, ?string $note = null): void
    {
        $booking->events()->create([
            'action' => $action,
            'actor' => $actor->label(),
            'employee_id' => $actor->employee?->id,
            'user_id' => $actor->user?->id,
            'from_status' => $from,
            'to_status' => $booking->status,
            'starts_at' => $booking->starts_at,
            'proposed_starts_at' => $booking->proposed_starts_at,
            'note' => $note,
        ]);
    }

    private function assertBookable(ServiceRequest $request): void
    {
        $blocker = $this->sessions->blocker($request);
        if ($blocker === null) {
            return;
        }
        $message = match ($blocker) {
            'unpaid' => 'هالطلب مو مدفوع أو مو مفتوح، ما فينا نحجز عليه تصوير.',
            'no_count' => 'عدد جلسات التصوير لهالطلب لسا ما انضبط.',
            'no_sessions' => 'هالطلب ما فيه جلسات تصوير.',
            'held' => 'الرصيد محجوز لموعد بانتظار الرد'.(($hold = $this->sessions->blockingHold($request)) ? ' يوم '.$this->when($hold->starts_at) : '').'.',
            default => 'خلصت جلسات التصوير بهالطلب.',
        };
        throw ValidationException::withMessages(['request' => $message]);
    }

    private function assertBeforeShootDay(PhotographyBooking $booking, ?string $message = null): void
    {
        $day = $this->clock($booking->starts_at)->startOfDay();
        if (now('Asia/Damascus')->gte($day)) {
            throw ValidationException::withMessages([
                'booking' => $message ?? 'بدأ يوم الموعد بتوقيت دمشق. الجلسة بتبقى بيومها.',
            ]);
        }
    }

    private function guardStart(string $startsAt, bool $overrideLead): Carbon
    {
        try {
            $start = $this->clock(Carbon::parse($startsAt, 'Asia/Damascus'));
        } catch (Throwable) {
            throw ValidationException::withMessages(['starts_at' => 'الوقت مو مفهوم. مثال: 2026-10-20 10:00']);
        }
        if ($overrideLead) {
            if ($start->lte(now('Asia/Damascus'))) {
                throw ValidationException::withMessages(['starts_at' => 'هذا الوقت مضى.']);
            }
        } else {
            $lead = $this->calendar->photographyLeadDays();
            $earliest = now('Asia/Damascus')->addDays($lead)->startOfDay();
            if ($start->lt($earliest)) {
                throw ValidationException::withMessages([
                    'starts_at' => "أقرب موعد للتصوير بعد {$lead} أيام.",
                ]);
            }
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

    /**
     * Another row whose held start sits within five hours of this one on the same
     * day. Agreed shoots and both ends of a move always count; holds count for staff.
     */
    private function conflict(Carbon $start, ?int $exceptBookingId, bool $withHolds): ?PhotographyBooking
    {
        $start = $this->clock($start);
        $statuses = $withHolds
            ? [PhotographyBooking::CONFIRMED, PhotographyBooking::RESCHEDULING, ...PhotographyBooking::HOLDS]
            : [PhotographyBooking::CONFIRMED, PhotographyBooking::RESCHEDULING];
        $from = $start->copy()->startOfDay()->format('Y-m-d H:i:s');
        $to = $start->copy()->endOfDay()->format('Y-m-d H:i:s');
        $rows = PhotographyBooking::query()
            ->whereIn('status', $statuses)
            ->when($exceptBookingId !== null, fn ($query) => $query->where('id', '!=', $exceptBookingId))
            ->where(function ($query) use ($from, $to): void {
                $query->whereBetween('starts_at', [$from, $to])->orWhereBetween('proposed_starts_at', [$from, $to]);
            })
            ->get();
        foreach ($rows as $row) {
            foreach ($row->heldStarts($withHolds) as $held) {
                if ($this->tooClose($start, $this->clock($held))) {
                    return $row;
                }
            }
        }

        return null;
    }

    private function conflictStart(PhotographyBooking $conflict, Carbon $fallback): Carbon
    {
        return $this->clock($conflict->starts_at ?? $fallback);
    }

    private function tooClose(Carbon $a, Carbon $b): bool
    {
        return $a->toDateString() === $b->toDateString()
            && abs($a->getTimestamp() - $b->getTimestamp()) < self::GAP_HOURS * 3600;
    }

    /** The first start five hours after the anchor that is free, on that day or a later work day. */
    private function offerAfter(Carbon $anchor): Carbon
    {
        $anchor = $this->clock($anchor);
        [$openMinute, $closeMinute] = $this->calendar->teamWindowMinutes();
        $candidate = $anchor->copy()->addHours(self::GAP_HOURS);
        for ($i = 0; $i < 14 * 24; $i++) {
            $minute = ($candidate->hour * 60) + $candidate->minute;
            if (! $this->calendar->isWorkDay($candidate) || $minute + (self::SHOOT_HOURS * 60) > $closeMinute) {
                $candidate = Carbon::parse($this->calendar->nextWorkStart($candidate)->format('Y-m-d'), 'Asia/Damascus')
                    ->addMinutes($openMinute);

                continue;
            }
            if ($minute < $openMinute) {
                $candidate = $candidate->copy()->startOfDay()->addMinutes($openMinute);

                continue;
            }
            if ($this->conflict($candidate, null, false) === null) {
                return $candidate;
            }
            $candidate = $candidate->copy()->addHour();
        }

        return $anchor->copy()->addHours(self::GAP_HOURS);
    }

    private function pendingText(PhotographyBooking $booking): string
    {
        $client = $booking->request?->client;

        return $this->say(
            $client,
            'وصلنا طلب الموعد يوم '.$this->when($booking->starts_at).' ('.$this->ref($booking->request).')، ومنرد عليك لتأكيده.',
            'We received your shoot time, '.$this->whenEn($booking->starts_at).' ('.$this->ref($booking->request).'). We will confirm it with you.',
        );
    }

    private function offerText(PhotographyBooking $booking): string
    {
        $client = $booking->request?->client;

        return $this->say(
            $client,
            $this->ref($booking->request).' — موعد #'.$booking->id.': الوقت يلي اخترته ما زبط. بيناسبك '.$this->when($booking->proposed_starts_at).'؟',
            $this->ref($booking->request).' — booking #'.$booking->id.': the time you picked is not available. Does '.$this->whenEn($booking->proposed_starts_at).' work for you?',
        );
    }

    private function confirmedText(PhotographyBooking $booking): string
    {
        $request = $booking->request;

        return $this->say(
            $request?->client,
            'تمام، ثبتنالك موعد التصوير '.$this->when($booking->starts_at).' ('.$this->ref($request).'). الجلسة '.self::SHOOT_HOURS.' ساعات، '.$this->balanceLine($request).'.'.$this->alarmLine($booking),
            'Your shoot is confirmed for '.$this->whenEn($booking->starts_at).' ('.$this->ref($request).'). The session lasts '.self::SHOOT_HOURS.' hours.'.$this->alarmLine($booking),
        );
    }

    private function movedText(PhotographyBooking $booking, ?Carbon $oldStart): string
    {
        $request = $booking->request;

        return $this->say(
            $request?->client,
            'تم نقل موعد التصوير ('.$this->ref($request).')'.($oldStart ? ' من '.$this->when($oldStart) : '').' إلى '.$this->when($booking->starts_at).'. الجلسة '.self::SHOOT_HOURS.' ساعات.'.$this->alarmLine($booking),
            'Your shoot ('.$this->ref($request).') moved to '.$this->whenEn($booking->starts_at).'.'.$this->alarmLine($booking),
        );
    }

    private function moveRequestedText(PhotographyBooking $booking, ?Carbon $previousProposal): string
    {
        $request = $booking->request;
        if ($previousProposal !== null) {
            return $this->say(
                $request?->client,
                'طلب تعديل موعد #'.$booking->id.' صار على '.$this->when($booking->proposed_starts_at).' بدل '.$this->when($previousProposal).'. الموعد الحالي '.$this->when($booking->starts_at).' باقي لحين الرد.',
                'Move request #'.$booking->id.' now asks for '.$this->whenEn($booking->proposed_starts_at).'. Your current time stays until we answer.',
            );
        }

        return $this->say(
            $request?->client,
            'طلب تعديل موعد #'.$booking->id.' ('.$this->ref($request).') من '.$this->when($booking->starts_at).' إلى '.$this->when($booking->proposed_starts_at).'، ومنرد عليك لتأكيده.',
            'Move request #'.$booking->id.' ('.$this->ref($request).') from '.$this->whenEn($booking->starts_at).' to '.$this->whenEn($booking->proposed_starts_at).'. We will confirm it with you.',
        );
    }

    /** The phone reminder link. A missing link never blocks the message. */
    private function alarmLine(PhotographyBooking $booking): string
    {
        try {
            $start = $this->clock($booking->starts_at);
            $url = URL::temporarySignedRoute('photography.alarm', $start->copy()->addDay(), ['booking' => $booking->id]);
        } catch (Throwable) {
            return '';
        }

        return $this->say($booking->request?->client, "\nلتسجيل التذكير على موبايلك: ", "\nAdd a phone reminder: ").$url;
    }

    private function say(?Client $client, string $arabic, string $english): string
    {
        return $client?->locale === 'en' ? $english : $arabic;
    }

    private function whenEn(?Carbon $time): string
    {
        return $time === null ? '' : $this->clock($time)->format('l j/n, g:i A');
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

    private function freshOrFail(PhotographyBooking $booking): PhotographyBooking
    {
        $fresh = $booking->fresh(['request.client', 'request.pricingPackage', 'employee']);
        if (! $fresh instanceof PhotographyBooking) {
            throw ValidationException::withMessages(['booking' => 'الموعد غير موجود.']);
        }

        return $fresh;
    }

    private function solePhotographer(): ?Employee
    {
        $photographers = Employee::query()->approved()->where('profession', EmployeeProfession::Media)->limit(2)->get();

        return $photographers->count() === 1 ? $photographers->first() : null;
    }

    private function effect(callable $effect): void
    {
        $this->effects[] = $effect;
    }

    /**
     * Runs the row change inside the booking lock, then the messages and integrations
     * outside it. An integration failure never undoes the row.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function run(callable $callback): mixed
    {
        $outer = $this->effects;
        $this->effects = [];
        try {
            $result = Cache::lock('photography-bookings', 20)->block(10, $callback);
        } catch (LockTimeoutException) {
            $this->effects = $outer;
            throw ValidationException::withMessages(['booking' => 'ما انحفظ، أعد الاختيار.']);
        } catch (Throwable $exception) {
            $this->effects = $outer;
            throw $exception;
        }
        $pending = $this->effects;
        $this->effects = $outer;
        foreach ($pending as $effect) {
            try {
                $effect();
            } catch (Throwable $exception) {
                Log::warning('Photography follow-up failed.', ['error' => $exception->getMessage()]);
            }
        }

        // Callers read the row as the follow-ups left it.
        if ($result instanceof PhotographyBooking) {
            return $result->fresh() ?? $result;
        }
        if (is_array($result) && ($result['booking'] ?? null) instanceof PhotographyBooking) {
            $result['booking'] = $result['booking']->fresh() ?? $result['booking'];
        }

        return $result;
    }

    private function clock(Carbon $time): Carbon
    {
        return Carbon::parse($time->format('Y-m-d H:i:s'), 'Asia/Damascus');
    }

    private function stored(Carbon $time): Carbon
    {
        return Carbon::parse($this->clock($time)->format('Y-m-d H:i:s'), 'UTC');
    }
}
