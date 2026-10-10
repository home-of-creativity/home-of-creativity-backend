<?php

namespace App\Http\Controllers\Admin;

use App\Actions\BookPhotographySlot;
use App\Actions\PhotographySessions;
use App\Enums\EmployeeProfession;
use App\Enums\StaffAbility;
use App\Http\Controllers\Controller;
use App\Models\PhotographyBooking;
use App\Models\PhotographyBookingEvent;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\PhotographyActor;
use App\Support\WorkCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The photography tab. Every button here calls the same booking operation as the
 * staff bot and the WhatsApp chat.
 */
class PhotographyController extends Controller
{
    public function __construct(
        private BookPhotographySlot $book,
        private PhotographySessions $sessions,
        private WorkCalendar $calendar,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if (Cache::add('hoc:photo-cal-sync:'.$user->id, 1, 45)) {
            try {
                $this->book->syncGoogleCalendar($user);
            } catch (\Throwable $exception) {
                Log::warning('Photography calendar sync failed.', ['error' => $exception->getMessage()]);
            }
        }
        $all = $user->canAbility(StaffAbility::OpsPhotographyAll);
        $employee = $user->employee;
        $today = now('Asia/Damascus')->startOfDay()->format('Y-m-d H:i:s');

        $rows = PhotographyBooking::query()
            ->with(['request.client', 'request.pricingPackage', 'employee'])
            ->where(function ($query) use ($today): void {
                $query->whereIn('status', PhotographyBooking::OPEN)
                    ->orWhere(fn ($agreed) => $agreed->where('status', PhotographyBooking::CONFIRMED)->where('starts_at', '>=', $today));
            })
            ->orderBy('starts_at')
            ->get();

        $queue = $rows->filter(fn (PhotographyBooking $row): bool => in_array($row->status, [PhotographyBooking::PENDING_STAFF, PhotographyBooking::RESCHEDULING], true));
        $waiting = $rows->where('status', PhotographyBooking::NEEDS_CLIENT);
        $mine = $employee === null ? collect() : $rows->filter(fn (PhotographyBooking $row): bool => $row->employee_id === $employee->id
            && in_array($row->status, [PhotographyBooking::CONFIRMED, PhotographyBooking::RESCHEDULING], true));

        $history = [];
        if ($all) {
            $status = (string) $request->query('status', '');
            $history = PhotographyBooking::query()
                ->with(['request.client', 'request.pricingPackage', 'employee'])
                ->when($status !== '', fn ($query) => $query->where('status', $status))
                ->latest('starts_at')
                ->limit(300)
                ->get()
                ->map(fn (PhotographyBooking $row): array => $this->row($row, $user))
                ->values()
                ->all();
        }

        return response()->json([
            'data' => [
                'calendar' => $rows->map(fn (PhotographyBooking $row): array => $this->row($row, $user))->values()->all(),
                'queue' => $queue->map(fn (PhotographyBooking $row): array => $this->row($row, $user))->values()->all(),
                'waiting_client' => $waiting->map(fn (PhotographyBooking $row): array => $this->row($row, $user))->values()->all(),
                'mine' => $mine->map(fn (PhotographyBooking $row): array => $this->row($row, $user))->values()->all(),
                'all' => $history,
                'unbooked' => $this->unbooked(),
                'needs_count' => $all ? $this->sessions->needingCount()->map(fn (ServiceRequest $item): array => $this->requestRow($item))->values()->all() : [],
                'me' => [
                    'employee_id' => $employee?->id,
                    'is_photographer' => $employee?->profession === EmployeeProfession::Media,
                    'can_all' => $all,
                ],
                'settings' => [
                    'lead_days' => $this->calendar->photographyLeadDays(),
                    'shoot_hours' => BookPhotographySlot::SHOOT_HOURS,
                    'gap_hours' => BookPhotographySlot::GAP_HOURS,
                    'reply_hours' => BookPhotographySlot::REPLY_HOURS,
                    'team_hours' => $this->calendar->teamHours(),
                ],
                'calendar_url' => $this->book->calendarHome(),
            ],
        ]);
    }

    public function show(Request $request, PhotographyBooking $booking): JsonResponse
    {
        $user = $this->user($request);
        $booking->load(['request.client', 'request.pricingPackage', 'employee']);

        return response()->json([
            'data' => array_merge($this->row($booking, $user), [
                'events' => $booking->events()->latest('id')->get()->map(fn (PhotographyBookingEvent $event): array => [
                    'id' => $event->id,
                    'action' => $event->action,
                    'actor' => $event->actor,
                    'from_status' => $event->from_status,
                    'to_status' => $event->to_status,
                    'starts_at' => $this->iso($event->starts_at),
                    'proposed_starts_at' => $this->iso($event->proposed_starts_at),
                    'note' => $event->note,
                    'created_at' => $event->created_at?->toIso8601String(),
                ])->all(),
            ]),
        ]);
    }

    /** Free starts on one day for a staff pick: five hours from agreed shoots and from holds. */
    public function slots(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'booking' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'data' => $this->book->timesOn(
                Carbon::parse($validated['date'], 'Asia/Damascus'),
                isset($validated['booking']) ? (int) $validated['booking'] : null,
                withHolds: true,
                allowSoon: true,
            ),
        ]);
    }

    /** Paid open requests that still have a session, for a manual booking. */
    public function requests(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $items = ServiceRequest::query()
            ->with(['client', 'pricingPackage'])
            ->where('photography_sessions', '>', 0)
            ->when($q !== '', function ($query) use ($q): void {
                $query->where(function ($inner) use ($q): void {
                    $inner->where('number', 'like', "%{$q}%")
                        ->orWhere('title', 'like', "%{$q}%")
                        ->orWhereHas('client', fn ($client) => $client->where('name', 'like', "%{$q}%")->orWhere('company_name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%"));
                });
            })
            ->latest('id')
            ->limit(200)
            ->get()
            ->filter(fn (ServiceRequest $item): bool => $this->sessions->isPaidOpen($item))
            ->take(50)
            ->map(fn (ServiceRequest $item): array => $this->requestRow($item))
            ->values()
            ->all();

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $validated = $request->validate([
            'request_id' => ['required', 'integer', 'exists:requests,id'],
            'starts_at' => ['required', 'string', 'max:40'],
            'confirm' => ['boolean'],
            'override_lead' => ['boolean'],
        ]);
        $all = $user->canAbility(StaffAbility::OpsPhotographyAll);
        $photographer = $user->employee?->profession === EmployeeProfession::Media;
        $confirm = (bool) ($validated['confirm'] ?? false);
        $override = (bool) ($validated['override_lead'] ?? false);
        // A photographer may agree a time for themself; everyone else needs the full ability.
        abort_unless($all || ($photographer && ($confirm || ! $override)), 403);
        $serviceRequest = ServiceRequest::query()->findOrFail($validated['request_id']);
        $booking = $this->book->book($serviceRequest, $validated['starts_at'], PhotographyActor::user($user), $confirm, $override);

        return $this->reply($booking, $user, $confirm ? 'تم تثبيت الموعد وخُصمت جلسة.' : 'حُفظ الموعد بانتظار القبول.', 201);
    }

    public function accept(Request $request, PhotographyBooking $booking): JsonResponse
    {
        $user = $this->user($request);
        $outcome = $this->book->accept($booking, PhotographyActor::user($user));

        return $this->reply($outcome['booking'], $user, match ($outcome['result']) {
            'confirmed' => 'تم تثبيت الموعد وأُبلغ العميل.',
            'moved' => 'تم نقل الموعد إلى الوقت الجديد.',
            'offered' => 'الوقت قريب من موعد مثبت، فعُرض على العميل وقت بعد 5 ساعات.',
            'closed' => 'الطلب لم يعد مدفوعاً أو مفتوحاً، فأُغلق الموعد بلا خصم.',
            default => 'تم الرد على هذا الموعد مسبقاً.',
        }, result: $outcome['result']);
    }

    public function decline(Request $request, PhotographyBooking $booking): JsonResponse
    {
        $user = $this->user($request);
        $outcome = $this->book->decline($booking, PhotographyActor::user($user));

        return $this->reply($outcome['booking'], $user, match ($outcome['result']) {
            'declined' => 'رُفض الموعد وتحررت الخانة.',
            'kept' => 'رُفض التعديل وبقي الموعد بوقته.',
            default => 'تم الرد على هذا الموعد مسبقاً.',
        }, result: $outcome['result']);
    }

    public function propose(Request $request, PhotographyBooking $booking): JsonResponse
    {
        $user = $this->user($request);
        $validated = $request->validate([
            'starts_at' => ['required', 'string', 'max:40'],
            'override_lead' => ['boolean'],
        ]);
        $override = (bool) ($validated['override_lead'] ?? false) && $this->mayOverride($user, $booking);
        $updated = $this->book->propose($booking, $validated['starts_at'], PhotographyActor::user($user), $override);
        $sent = ! $updated->client_notify_failed && $updated->client_notified_at !== null;

        return $this->reply($updated, $user, $sent ? 'أُرسل الوقت المقترح للعميل.' : 'الوقت محفوظ، لكن رسالة العميل لم تصل. أعد الإرسال.');
    }

    public function reschedule(Request $request, PhotographyBooking $booking): JsonResponse
    {
        $user = $this->user($request);
        $validated = $request->validate([
            'starts_at' => ['required', 'string', 'max:40'],
            'direct' => ['boolean'],
            'override_lead' => ['boolean'],
        ]);
        $direct = (bool) ($validated['direct'] ?? false);
        $override = (bool) ($validated['override_lead'] ?? false);
        if ($direct || $override) {
            abort_unless($this->mayOverride($user, $booking), 403);
        }
        $updated = $this->book->reschedule($booking, $validated['starts_at'], PhotographyActor::user($user), $direct, $override);

        return $this->reply($updated, $user, $direct ? 'نُقل الموعد وأُبلغ العميل.' : 'حُفظ طلب التعديل بانتظار المصور. الوقت القديم باقٍ.');
    }

    public function cancel(Request $request, PhotographyBooking $booking): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($user->canAbility(StaffAbility::OpsPhotographyAll), 403);
        $updated = $this->book->cancel($booking, PhotographyActor::user($user));

        return $this->reply($updated, $user, $updated->refunded_at !== null ? 'أُلغي الموعد ورُدّت الجلسة إلى الرصيد.' : 'أُلغي الموعد.');
    }

    public function done(Request $request, PhotographyBooking $booking): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($this->mayOverride($user, $booking), 403);

        return $this->reply($this->book->markDone($booking, PhotographyActor::user($user)), $user, 'عُلّم الموعد: تم التصوير.');
    }

    public function noShow(Request $request, PhotographyBooking $booking): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($this->mayOverride($user, $booking), 403);

        return $this->reply($this->book->markNoShow($booking, PhotographyActor::user($user)), $user, 'عُلّم الموعد: لم يحضر العميل.');
    }

    public function refund(Request $request, PhotographyBooking $booking): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($user->canAbility(StaffAbility::OpsPhotographyAll), 403);

        return $this->reply($this->book->refundNoShow($booking, PhotographyActor::user($user)), $user, 'رُدّت الجلسة إلى الرصيد.');
    }

    public function resend(Request $request, PhotographyBooking $booking): JsonResponse
    {
        $user = $this->user($request);
        $updated = $this->book->resendClient($booking);

        return $this->reply($updated, $user, $updated->client_notify_failed ? 'لم تصل الرسالة مرة أخرى.' : 'أُعيد إرسال الرسالة للعميل.');
    }

    public function retryCalendar(Request $request, PhotographyBooking $booking): JsonResponse
    {
        $user = $this->user($request);
        $updated = $this->book->retryCalendar($booking);

        return $this->reply($updated, $user, $updated->calendar_failed_at === null ? 'تم تحديث التقويم.' : 'التقويم ما زال ناقصاً.');
    }

    public function retryClickUp(Request $request, PhotographyBooking $booking): JsonResponse
    {
        $user = $this->user($request);
        $updated = $this->book->retryClickUp($booking);

        return $this->reply($updated, $user, $updated->clickup_failed_at === null ? 'تم تحديث مهمة ClickUp.' : 'ClickUp ما زال يرفض.');
    }

    public function sessions(Request $request, ServiceRequest $serviceRequest): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($user->canAbility(StaffAbility::OpsPhotographyAll), 403);
        $validated = $request->validate([
            'sessions' => ['required', 'integer', 'min:0', 'max:100'],
        ]);
        $updated = $this->sessions->setCap($serviceRequest, (int) $validated['sessions']);

        return response()->json(['data' => $this->requestRow($updated->load(['client', 'pricingPackage'])), 'message' => 'حُفظ عدد الجلسات.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(PhotographyBooking $booking, User $user): array
    {
        $request = $booking->request;
        $status = $booking->status;
        $agreed = in_array($status, [PhotographyBooking::CONFIRMED, PhotographyBooking::RESCHEDULING], true);
        $start = $booking->starts_at === null ? null : Carbon::parse($booking->starts_at->format('Y-m-d H:i:s'), 'Asia/Damascus');
        $end = $booking->ends_at === null ? null : Carbon::parse($booking->ends_at->format('Y-m-d H:i:s'), 'Asia/Damascus');
        $now = now('Asia/Damascus');
        $beforeDay = $start !== null && $now->lt($start->copy()->startOfDay());
        $ended = $end !== null && $now->gte($end);
        $deadline = $booking->waiting_since?->copy()->addHours(BookPhotographySlot::REPLY_HOURS);
        $all = $user->canAbility(StaffAbility::OpsPhotographyAll);
        $owner = $this->mayOverride($user, $booking);

        return [
            'id' => $booking->id,
            'status' => $status,
            'request' => $request === null ? null : $this->requestRow($request),
            'client' => $request?->client === null ? null : [
                'id' => $request->client->id,
                'name' => $request->client->name,
                'company_name' => $request->client->company_name,
                'phone' => $request->client->phone,
            ],
            'session_label' => $booking->isCharged() || $agreed ? $this->book->sessionLabel($booking) : null,
            'starts_at' => $start?->toIso8601String(),
            'ends_at' => $end?->toIso8601String(),
            'proposed_starts_at' => $this->iso($booking->proposed_starts_at),
            'when' => $this->book->when($booking->starts_at),
            'proposed_when' => $this->book->when($booking->proposed_starts_at),
            'employee' => $booking->employee === null ? null : ['id' => $booking->employee->id, 'name' => $booking->employee->name],
            'waiting_since' => $booking->waiting_since?->toIso8601String(),
            'expires_at' => in_array($status, PhotographyBooking::OPEN, true) ? $deadline?->toIso8601String() : null,
            'late' => in_array($status, PhotographyBooking::OPEN, true) && $deadline !== null && now()->gte($deadline->copy()->subHour()),
            'charged' => $booking->isCharged(),
            'refunded_at' => $booking->refunded_at?->toIso8601String(),
            'lead_override' => $booking->lead_override_by !== null,
            'client_notified_at' => $booking->client_notified_at?->toIso8601String(),
            'client_notify_failed' => (bool) $booking->client_notify_failed,
            'calendar_missing' => $booking->calendar_failed_at !== null,
            'clickup_stale' => $booking->clickup_failed_at !== null,
            'calendar_url' => $this->book->calendarLink($booking->google_event_id),
            'can' => [
                'accept' => in_array($status, [PhotographyBooking::PENDING_STAFF, PhotographyBooking::RESCHEDULING], true),
                'decline' => in_array($status, [PhotographyBooking::PENDING_STAFF, PhotographyBooking::NEEDS_CLIENT, PhotographyBooking::RESCHEDULING], true),
                'propose' => in_array($status, PhotographyBooking::HOLDS, true),
                'reschedule' => in_array($status, PhotographyBooking::HOLDS, true) || ($agreed && $beforeDay),
                'reschedule_direct' => $owner && (in_array($status, PhotographyBooking::HOLDS, true) || ($agreed && $beforeDay)),
                'cancel' => $all && (in_array($status, PhotographyBooking::HOLDS, true) || ($agreed && $beforeDay)),
                'done' => $owner && $status === PhotographyBooking::CONFIRMED && $ended,
                'noshow' => $owner && $status === PhotographyBooking::CONFIRMED && $ended,
                'refund' => $all && $status === PhotographyBooking::NOSHOW && $booking->isCharged(),
                'resend' => in_array($status, [...PhotographyBooking::OPEN, PhotographyBooking::CONFIRMED], true),
                'retry_calendar' => $status === PhotographyBooking::CONFIRMED && $booking->calendar_failed_at !== null,
                'retry_clickup' => $agreed && $booking->clickup_failed_at !== null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestRow(ServiceRequest $request): array
    {
        $cap = $this->sessions->cap($request);

        return [
            'id' => $request->id,
            'number' => $request->number,
            'title' => $request->title,
            'status' => $request->status->value,
            'package' => $request->pricingPackage?->name_ar ?: $request->pricingPackage?->name_en,
            'client_name' => $request->client?->name,
            'company_name' => $request->client?->company_name,
            'sessions' => $cap,
            'used' => $this->sessions->used($request),
            'holds' => $this->sessions->holds($request),
            'remaining' => $this->sessions->remaining($request),
            'blocker' => $this->sessions->blocker($request),
        ];
    }

    /**
     * Paid requests with a free session and nothing asked or agreed ahead.
     *
     * @return list<array<string, mixed>>
     */
    private function unbooked(): array
    {
        $today = now('Asia/Damascus')->startOfDay()->format('Y-m-d H:i:s');

        return ServiceRequest::query()
            ->with(['client', 'pricingPackage'])
            ->where('photography_sessions', '>', 0)
            ->whereDoesntHave('photographyBookings', function ($query) use ($today): void {
                $query->whereIn('status', PhotographyBooking::OPEN)
                    ->orWhere(fn ($agreed) => $agreed->where('status', PhotographyBooking::CONFIRMED)->where('starts_at', '>=', $today));
            })
            ->latest('id')
            ->limit(300)
            ->get()
            ->filter(fn (ServiceRequest $item): bool => $this->sessions->isPaidOpen($item) && ! $item->hiddenFromClient() && $this->sessions->remaining($item) > 0)
            ->take(100)
            ->map(fn (ServiceRequest $item): array => $this->requestRow($item))
            ->values()
            ->all();
    }

    /** Staff with the full ability, or the photographer this booking belongs to. */
    private function mayOverride(User $user, PhotographyBooking $booking): bool
    {
        if ($user->canAbility(StaffAbility::OpsPhotographyAll)) {
            return true;
        }
        $employee = $user->employee;

        return $employee !== null && $booking->employee_id === $employee->id;
    }

    private function reply(PhotographyBooking $booking, User $user, string $message, int $status = 200, ?string $result = null): JsonResponse
    {
        $booking = $booking->fresh(['request.client', 'request.pricingPackage', 'employee']) ?? $booking;

        return response()->json([
            'data' => $this->row($booking, $user),
            'result' => $result,
            'message' => $message,
        ], $status);
    }

    private function iso(?Carbon $time): ?string
    {
        return $time === null ? null : Carbon::parse($time->format('Y-m-d H:i:s'), 'Asia/Damascus')->toIso8601String();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $user->loadMissing('employee');

        return $user;
    }
}
