<?php

namespace App\Actions;

use App\Models\ServiceRequest;
use App\Support\WorkCalendar;
use Illuminate\Validation\ValidationException;

class ExtendClientSchedule
{
    public function __construct(
        private WorkCalendar $calendar,
        private NotifyClientChannels $notifyClientChannels,
    ) {}

    public function handle(ServiceRequest $request, int $hours, string $reason): ServiceRequest
    {
        $reason = trim($reason);
        if ($reason === '' || $hours < 1) {
            throw ValidationException::withMessages([
                'reason' => 'سبب زيادة المدة مطلوب.',
            ]);
        }

        $base = $request->client_due_at ?? $request->subscription_ends_at ?? now();
        $request->forceFill([
            'client_due_at' => $this->calendar->addWorkHours($base, $hours),
            'schedule_extension_reason' => $reason,
        ])->save();

        $this->notifyClientChannels->send(
            $request,
            "تمديد مدة الطلب #{$request->number} لظرف من الشركة.\n{$reason}",
        );

        return $request->fresh() ?? $request;
    }
}
