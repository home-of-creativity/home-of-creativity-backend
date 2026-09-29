<?php

namespace App\Actions;

use App\Enums\EmployeeProfession;
use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Support\ResolveServiceRequest;
use App\Support\WorkLines;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConfirmWorkPlan
{
    public function __construct(
        private ResolveWorkPlan $resolveWorkPlan,
        private ProvisionClickUpTasks $provisionClickUpTasks,
        private NotifyEmployees $notifyEmployees,
        private NotifyClientChannels $notifyClientChannels,
    ) {}

    public function handle(ServiceRequest $request): ServiceRequest
    {
        if ($request->paid_at === null && ! in_array($request->status, [
            RequestStatus::PaymentConfirmed,
            RequestStatus::InProgress,
            RequestStatus::Completed,
        ], true)) {
            throw ValidationException::withMessages([
                'plan' => 'الخطة تُؤكد بعد موافقة الدفع.',
            ]);
        }

        $request->loadMissing('clickupTasks');
        $newPeriod = $this->needsNewPeriod($request);
        if ($request->plan_confirmed_at !== null && $request->clickupTasks->isNotEmpty() && ! $newPeriod) {
            return $request;
        }

        if ($newPeriod) {
            $request->forceFill([
                'work_plan' => array_merge(is_array($request->work_plan) ? $request->work_plan : [], [
                    'period_key' => 'period:'.($request->subscription_ends_at?->timestamp ?? time()),
                    'allow_new_period' => true,
                ]),
            ])->save();
        }

        if (WorkLines::fromRequest($request) === [] && $request->pricing_package_id === null && mb_strlen(trim((string) $request->description)) < 15) {
            throw ValidationException::withMessages([
                'description' => 'صف الناتج المطلوب بجملة واحدة أوضح، ثم أكّد الخطة.',
            ]);
        }

        $plan = $this->resolveWorkPlan->handle($request->fresh() ?? $request, $newPeriod);
        $ready = $request->fresh() ?? $request;
        if ($newPeriod) {
            $ready->forceFill([
                'work_plan' => array_merge(is_array($ready->work_plan) ? $ready->work_plan : [], [
                    'allow_new_period' => true,
                ]),
            ])->save();
            $ready = $ready->fresh() ?? $ready;
        }
        $updated = $this->provisionClickUpTasks->handle($ready, (string) Str::uuid());
        $updated->forceFill([
            'plan_confirmed_at' => now(),
            'client_due_at' => $updated->client_due_at ?? ($plan['client_due_at'] ?? null),
        ])->save();

        $ref = ResolveServiceRequest::displayNumber($updated);
        $this->notifyClientChannels->send($updated, 'بدأ التنفيذ');
        $this->notifyEmployees->handle(
            $updated,
            EmployeeProfession::Sales,
            "الخطة جاهزة للطلب #{$ref}.",
        );

        return $updated->fresh(['client', 'clickupTasks', 'pricingPackage']) ?? $updated;
    }

    private function needsNewPeriod(ServiceRequest $request): bool
    {
        if ($request->subscription_ends_at === null || $request->clickupTasks->isEmpty()) {
            return false;
        }

        $plannedEnd = (string) data_get($request->work_plan, 'period_end', '');
        if ($plannedEnd === '') {
            return false;
        }

        return $request->subscription_ends_at->greaterThan(\Illuminate\Support\Carbon::parse($plannedEnd)->addDay());
    }
}
