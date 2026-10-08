<?php

namespace App\Http\Controllers\Admin;

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
            'hours' => ['required', 'integer', 'min:1', 'max:80'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return new ServiceRequestResource($extendClientSchedule->handle(
            $serviceRequest,
            (int) $validated['hours'],
            $validated['reason'],
        ));
    }
}
