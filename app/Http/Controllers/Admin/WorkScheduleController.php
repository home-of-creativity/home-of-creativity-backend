<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ChangeWorkPlan;
use App\Actions\ConfirmWorkPlan;
use App\Actions\ExtendClientSchedule;
use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceRequestResource;
use App\Models\ServiceRequest;
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
        ]);

        $updated = filled($validated['days'] ?? null)
            ? $extendClientSchedule->byDays($serviceRequest, (int) $validated['days'], $validated['reason'])
            : $extendClientSchedule->handle($serviceRequest, (int) $validated['hours'], $validated['reason']);

        return (new ServiceRequestResource($updated))->additional(['message' => 'مُدّدت المدة في ClickUp وأُبلغ الزبون بموعد التسليم الجديد.']);
    }

    /** «تغيير الخطة»: the approved plan with every department and its staff. */
    public function showPlan(ServiceRequest $serviceRequest, ChangeWorkPlan $changeWorkPlan): JsonResponse
    {
        return response()->json(['data' => $changeWorkPlan->current($serviceRequest), 'message' => 'ok']);
    }

    public function changePlan(Request $request, ServiceRequest $serviceRequest, ChangeWorkPlan $changeWorkPlan): ServiceRequestResource
    {
        $validated = $request->validate([
            'operations' => ['present', 'array', 'max:3'],
            'operations.*.department' => ['required', 'string', 'distinct', 'in:'.implode(',', ChangeWorkPlan::DEPARTMENTS)],
            'operations.*.brief' => ['required', 'string', 'max:4000'],
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
