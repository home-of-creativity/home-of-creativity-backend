<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ChangeWorkPlan;
use App\Actions\ConfirmWorkPlan;
use App\Actions\ExtendClientSchedule;
use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceRequestResource;
use App\Models\ServiceRequest;
use App\Services\GeminiService;
use App\Support\WorkCalendar;
use App\Support\WorkLines;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WorkScheduleController extends Controller
{
    public function showCalendar(WorkCalendar $calendar): JsonResponse
    {
        return response()->json([
            'data' => [
                'hours_per_day' => $calendar->hoursPerDay(),
                'holidays' => $calendar->holidays(),
                'whatsapp_open' => $calendar->whatsappHours()['open'],
                'whatsapp_close' => $calendar->whatsappHours()['close'],
                'team_open' => $calendar->teamHours()['open'],
                'team_close' => $calendar->teamHours()['close'],
                'photography_lead_days' => $calendar->photographyLeadDays(),
                'work_days' => ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday'],
                'weekend' => 'friday',
            ],
        ]);
    }

    public function updateCalendar(Request $request, WorkCalendar $calendar): JsonResponse
    {
        $validated = $request->validate([
            'hours_per_day' => ['required', 'integer', 'min:1', 'max:16'],
            'holidays' => ['array'],
            'holidays.*' => ['date_format:Y-m-d'],
            'whatsapp_open' => ['nullable', 'date_format:H:i'],
            'whatsapp_close' => ['nullable', 'date_format:H:i'],
            'team_open' => ['nullable', 'date_format:H:i'],
            'team_close' => ['nullable', 'date_format:H:i'],
            'photography_lead_days' => ['nullable', 'integer', 'min:0', 'max:90'],
        ]);

        $calendar->saveHoursPerDay((int) $validated['hours_per_day']);
        $calendar->saveHolidays($validated['holidays'] ?? []);
        if (filled($validated['whatsapp_open'] ?? null) && filled($validated['whatsapp_close'] ?? null)) {
            if ((string) $validated['whatsapp_close'] === (string) $validated['whatsapp_open']) {
                throw ValidationException::withMessages([
                    'whatsapp_close' => 'WhatsApp opening and closing times must differ.',
                ]);
            }
            $calendar->saveWhatsAppHours((string) $validated['whatsapp_open'], (string) $validated['whatsapp_close']);
        }
        if (filled($validated['team_open'] ?? null) && filled($validated['team_close'] ?? null)) {
            if ((string) $validated['team_close'] <= (string) $validated['team_open']) {
                throw ValidationException::withMessages([
                    'team_close' => 'Team closing time must be after opening time.',
                ]);
            }
            $calendar->saveTeamHours((string) $validated['team_open'], (string) $validated['team_close']);
        }
        if (array_key_exists('photography_lead_days', $validated) && $validated['photography_lead_days'] !== null) {
            $calendar->savePhotographyLeadDays((int) $validated['photography_lead_days']);
        }

        return $this->showCalendar($calendar);
    }

    public function confirmPlan(ServiceRequest $serviceRequest, ConfirmWorkPlan $confirmWorkPlan): ServiceRequestResource
    {
        return new ServiceRequestResource($confirmWorkPlan->handle($serviceRequest));
    }

    public function draftLines(Request $request, ServiceRequest $serviceRequest): ServiceRequestResource
    {
        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.department' => ['required', 'string', 'in:design,content,programming,photography'],
            'lines.*.hours' => ['required', 'integer', 'min:1', 'max:400'],
        ]);

        $serviceRequest->forceFill([
            'draft_work_lines' => WorkLines::normalize($validated['lines']),
        ])->save();

        return new ServiceRequestResource($serviceRequest->fresh() ?? $serviceRequest);
    }

    public function extendSchedule(
        Request $request,
        ServiceRequest $serviceRequest,
        ExtendClientSchedule $extendClientSchedule,
    ): ServiceRequestResource {
        $validated = $request->validate([
            'days' => ['required_without:hours', 'nullable', 'integer', 'min:1', 'max:60'],
            'hours' => ['required_without:days', 'nullable', 'integer', 'min:1', 'max:80'],
            'reason' => ['required', 'string', 'max:500'],
            'departments' => ['nullable', 'array', 'max:3'],
            'departments.*' => ['string', 'distinct', 'in:'.implode(',', ChangeWorkPlan::DEPARTMENTS)],
        ]);
        $departments = isset($validated['departments']) ? array_values($validated['departments']) : null;
        $before = $serviceRequest->client_due_at?->toIso8601String();

        $updated = filled($validated['days'] ?? null)
            ? $extendClientSchedule->byDays($serviceRequest, (int) $validated['days'], $validated['reason'], $departments)
            : $extendClientSchedule->handle($serviceRequest, (int) $validated['hours'], $validated['reason'], $departments);
        $moved = $updated->client_due_at?->toIso8601String() !== $before;

        return (new ServiceRequestResource($updated))->additional([
            'message' => $moved
                ? 'مُدّدت المدة في ClickUp وأُبلغ الزبون بموعد التسليم الجديد.'
                : 'مُدّدت مهام الأقسام المختارة في ClickUp. موعد التسليم للزبون لم يتغير، فلم يُبلَّغ.',
        ]);
    }

    /** «تغيير الخطة»: the approved plan with every department and its staff. */
    public function showPlan(ServiceRequest $serviceRequest, ChangeWorkPlan $changeWorkPlan): JsonResponse
    {
        return response()->json(['data' => $changeWorkPlan->current($serviceRequest), 'message' => 'ok']);
    }

    /** Gemini's tasks for one department, to compare with the dialog's and apply. Nothing is saved. */
    public function suggestTasks(Request $request, ServiceRequest $serviceRequest, GeminiService $gemini): JsonResponse
    {
        $validated = $request->validate([
            'department' => ['required', 'string', 'in:'.implode(',', ChangeWorkPlan::DEPARTMENTS)],
            'brief' => ['nullable', 'string', 'max:4000'],
            'tasks' => ['nullable', 'array', 'max:40'],
            'tasks.*.title' => ['required', 'string', 'max:200'],
            'tasks.*.description' => ['nullable', 'string', 'max:4000'],
        ]);
        $current = array_map(
            fn (array $task): array => ['title' => $task['title'], 'description' => $task['description']],
            ChangeWorkPlan::tasks($validated['tasks'] ?? []),
        );
        $tasks = $gemini->suggestDepartmentTasks(
            $validated['department'],
            (string) $serviceRequest->title,
            mb_substr((string) $serviceRequest->description, 0, 3000),
            ChangeWorkPlan::packageFeatures($serviceRequest),
            (string) ($validated['brief'] ?? ''),
            $current,
        );

        return response()->json([
            'data' => ['tasks' => $tasks],
            'message' => $tasks === $current ? 'لم يقترح Gemini تغييراً على المهام.' : 'راجع اقتراح Gemini ثم طبّقه.',
        ]);
    }

    public function changePlan(Request $request, ServiceRequest $serviceRequest, ChangeWorkPlan $changeWorkPlan): ServiceRequestResource
    {
        $validated = $request->validate([
            'operations' => ['present', 'array', 'max:3'],
            'operations.*.department' => ['required', 'string', 'distinct', 'in:'.implode(',', ChangeWorkPlan::DEPARTMENTS)],
            'operations.*.brief' => ['nullable', 'string', 'max:4000'],
            'operations.*.tasks' => ['nullable', 'array', 'max:40'],
            'operations.*.tasks.*.title' => ['required', 'string', 'max:200'],
            'operations.*.tasks.*.description' => ['nullable', 'string', 'max:4000'],
            'operations.*.tasks.*.clickup_task_id' => ['nullable', 'string', 'max:40'],
            'operations.*.hours' => ['required', 'integer', 'min:1', 'max:400'],
            'operations.*.priority' => ['nullable', 'integer', 'min:1', 'max:4'],
            'operations.*.employee_id' => ['nullable', 'integer'],
            'photography_sessions' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $result = $changeWorkPlan->handle(
            $serviceRequest,
            array_values($validated['operations']),
            (int) $validated['photography_sessions'],
            'admin:'.$request->user()?->id,
        );

        return (new ServiceRequestResource($result['request']))->additional([
            'message' => $result['errors'] === []
                ? 'حُفظت الخطة وحُدّثت مهام ClickUp.'
                : 'حُفظت الخطة، وتعذر تحديث بعض مهام ClickUp: '.implode(' · ', $result['errors']),
            'clickup_errors' => $result['errors'],
        ]);
    }
}
