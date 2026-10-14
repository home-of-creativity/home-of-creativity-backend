<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReportCalendar;
use App\Support\ReportCalendarAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Calendars on the reports calendar page. Creating one adds its view permission to
 * the permissions page; the roles picked here (or there) are the ones that see it.
 */
class ReportCalendarListController extends Controller
{
    public function __construct(private ReportCalendarAccess $access) {}

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $calendar = DB::transaction(function () use ($data, $request): ReportCalendar {
            $calendar = ReportCalendar::query()->create([
                'name' => $data['name'],
                'color' => $data['color'],
                'created_by' => $request->user()?->id,
            ]);
            $roleIds = $data['role_ids'];
            // A staff member who creates a calendar keeps seeing it through their own role.
            $own = $request->user()?->role_id;
            if ($own !== null && ! in_array((int) $own, $roleIds, true)) {
                $roleIds[] = (int) $own;
            }
            $this->access->syncRoles($calendar, $roleIds);

            return $calendar;
        });

        return response()->json(['data' => $this->access->payload($calendar), 'message' => 'Created.'], 201);
    }

    public function update(Request $request, ReportCalendar $reportCalendar): JsonResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data, $reportCalendar, $request): void {
            $reportCalendar->fill(['name' => $data['name'], 'color' => $data['color']])->save();
            $roleIds = $data['role_ids'];
            $own = $request->user()?->role_id;
            if ($own !== null && ! in_array((int) $own, $roleIds, true)) {
                $roleIds[] = (int) $own;
            }
            $this->access->syncRoles($reportCalendar, $roleIds);
        });

        return response()->json(['data' => $this->access->payload($reportCalendar->fresh()), 'message' => 'Saved.']);
    }

    /** Deletes the calendar with its deadlines and takes its permission off every role. */
    public function destroy(ReportCalendar $reportCalendar): JsonResponse
    {
        DB::transaction(function () use ($reportCalendar): void {
            $this->access->syncRoles($reportCalendar, []);
            $reportCalendar->delete();
        });

        return response()->json(['data' => null, 'message' => 'Deleted.']);
    }

    /**
     * @return array{name: string, color: string|null, role_ids: list<int>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')],
        ]);

        return [
            'name' => trim($data['name']),
            'color' => $data['color'] ?? null,
            'role_ids' => array_values(array_unique(array_map('intval', $data['role_ids'] ?? []))),
        ];
    }
}
