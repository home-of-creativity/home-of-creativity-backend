<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ReportDeadlines;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClientReportVersionResource;
use App\Models\ClientReport;
use App\Models\ClientReportVersion;
use App\Models\ReportCalendar;
use App\Models\ReportDeadline;
use App\Models\ReportDeadlineOccurrence;
use App\Models\Role;
use App\Support\ReportCalendarAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The reports calendar: due dates staff plan per client, beside the days reports were
 * edited, published, or restored.
 */
class ReportCalendarController extends Controller
{
    private const ACTIVITY_LIMIT = 1500;

    public function __construct(private ReportCalendarAccess $access) {}

    public function index(Request $request, ReportDeadlines $deadlines): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
            'client_id' => ['nullable', 'integer'],
        ]);
        $from = CarbonImmutable::parse($data['from']);
        $to = CarbonImmutable::parse($data['to']);
        abort_if($from->diffInDays($to) > 400, 422, 'Pick a range of at most one year.');
        $clientId = isset($data['client_id']) ? (int) $data['client_id'] : null;

        $activity = ClientReportVersion::query()
            ->with(['user', 'report', 'client'])
            ->whereIn('event', [
                ClientReportVersion::CREATED,
                ClientReportVersion::EDITED,
                ClientReportVersion::PUBLISHED,
                ClientReportVersion::RESTORED,
                ClientReportVersion::DELETED,
            ])
            ->when($clientId, fn ($query) => $query->where('client_id', $clientId))
            ->where('updated_at', '>=', $from->utc())
            ->where('started_at', '<', $to->utc())
            ->orderBy('started_at')
            ->limit(self::ACTIVITY_LIMIT)
            ->get();

        $calendars = $this->access->visible($request->user());
        $canManage = $this->access->canManage($request->user());

        return response()->json([
            'data' => [
                'deadlines' => $deadlines->between(
                    $from,
                    $to,
                    $clientId,
                    array_map(fn (ReportCalendar $calendar): int => $calendar->id, $calendars),
                    $this->access->canView($request->user(), null),
                ),
                'main_visible' => $this->access->canView($request->user(), null),
                'activity' => ClientReportVersionResource::collection($activity)->resolve(),
                'calendars' => array_map(fn (ReportCalendar $calendar): array => $this->access->payload($calendar), $calendars),
                'can_manage_calendars' => $canManage,
                'roles' => $canManage
                    ? Role::query()->orderBy('name')->get(['id', 'name'])->map(fn (Role $role): array => ['id' => $role->id, 'name' => $role->name])->all()
                    : [],
            ],
            'message' => 'ok',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $deadline = ReportDeadline::query()->create([
            ...$this->validated($request),
            'user_id' => $request->user()?->id,
        ]);

        return response()->json(['data' => ['id' => $deadline->id], 'message' => 'Saved.'], 201);
    }

    /**
     * Edits the whole series. When its first date moves, dates already marked keep their
     * place in the series by moving with it.
     */
    public function update(Request $request, ReportDeadline $reportDeadline): JsonResponse
    {
        $this->assertVisible($request, $reportDeadline);
        $data = $this->validated($request);
        $shift = (int) CarbonImmutable::parse((string) ReportDeadlines::day($reportDeadline->starts_on))
            ->diffInDays(CarbonImmutable::parse($data['starts_on']), false);

        DB::transaction(function () use ($reportDeadline, $data, $shift): void {
            if ($shift !== 0) {
                // Walk away from the move so no row lands on a date another row still holds.
                $reportDeadline->occurrences()->orderBy('occurs_on', $shift > 0 ? 'desc' : 'asc')->get()
                    ->each(function (ReportDeadlineOccurrence $row) use ($shift): void {
                        $row->forceFill([
                            'occurs_on' => CarbonImmutable::parse((string) ReportDeadlines::day($row->occurs_on))->addDays($shift)->format('Y-m-d'),
                        ])->save();
                    });
            }
            $reportDeadline->fill($data)->save();
        });

        return response()->json(['data' => ['id' => $reportDeadline->id], 'message' => 'Saved.']);
    }

    public function destroy(Request $request, ReportDeadline $reportDeadline): JsonResponse
    {
        $this->assertVisible($request, $reportDeadline);
        $reportDeadline->delete();

        return response()->json(['data' => null, 'message' => 'Deleted.']);
    }

    /** One date of the series: done, undone, skipped, restored, or moved. */
    public function occurrence(Request $request, ReportDeadline $reportDeadline, string $date, ReportDeadlines $deadlines): JsonResponse
    {
        $this->assertVisible($request, $reportDeadline);
        $data = $request->validate([
            'action' => ['required', Rule::in(['done', 'undo', 'skip', 'unskip', 'move', 'reset'])],
            'date' => ['required_if:action,move', 'nullable', 'date_format:Y-m-d'],
            'time' => ['nullable', 'date_format:H:i'],
            'client_report_id' => ['nullable', 'integer'],
        ]);
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1, 404);
        $first = CarbonImmutable::parse($date);
        abort_unless(in_array($date, $deadlines->dates($reportDeadline, $first, $first->addDay()), true), 404, 'This date is not part of the series.');

        $reportId = $data['client_report_id'] ?? null;
        if ($reportId !== null) {
            abort_unless(ClientReport::query()->whereKey($reportId)->where('client_id', $reportDeadline->client_id)->exists(), 422, 'Pick a report of this client.');
        }

        $row = ReportDeadlineOccurrence::query()->firstOrNew([
            'report_deadline_id' => $reportDeadline->id,
            'occurs_on' => $date,
        ]);
        match ($data['action']) {
            'done' => $row->forceFill(['done_at' => now(), 'done_by' => $request->user()?->id, 'client_report_id' => $reportId, 'skipped' => false]),
            'undo' => $row->forceFill(['done_at' => null, 'done_by' => null, 'client_report_id' => null]),
            'skip' => $row->forceFill(['skipped' => true, 'done_at' => null, 'done_by' => null, 'client_report_id' => null]),
            'unskip' => $row->forceFill(['skipped' => false]),
            'move' => $row->forceFill(['moved_on' => $data['date'], 'moved_time' => $data['time'] ?? null]),
            'reset' => $row->forceFill(['moved_on' => null, 'moved_time' => null]),
        };

        $blank = $row->done_at === null && ! $row->skipped && $row->moved_on === null;
        if ($blank) {
            if ($row->exists) {
                $row->delete();
            }
        } else {
            $row->save();
        }

        return response()->json(['data' => null, 'message' => 'Saved.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'report_calendar_id' => ['nullable', 'integer', Rule::exists('report_calendars', 'id')],
            'title' => ['required', 'string', 'max:160'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'due_time' => ['nullable', 'date_format:H:i'],
            'repeat' => ['required', Rule::in(ReportDeadline::REPEATS)],
            'repeat_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($data['repeat'] === 'none') {
            $data['repeat_until'] = null;
        }
        $calendarId = isset($data['report_calendar_id']) ? (int) $data['report_calendar_id'] : null;
        abort_unless($this->access->canView($request->user(), $calendarId), 403, 'You cannot add to this calendar.');

        return $data + ['report_calendar_id' => null, 'due_time' => null, 'repeat_until' => null, 'color' => null, 'notes' => null];
    }

    /** A deadline on a calendar the staff member does not see does not exist for them. */
    private function assertVisible(Request $request, ReportDeadline $deadline): void
    {
        abort_unless($this->access->canSeeDeadline($request->user(), $deadline), 404);
    }
}
