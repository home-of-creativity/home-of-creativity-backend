<?php

namespace App\Actions;

use App\Enums\ClickUpTaskType;
use App\Enums\EmployeeProfession;
use App\Enums\RequestStatus;
use App\Models\ClickUpTask;
use App\Models\Employee;
use App\Models\ServiceRequest;
use App\Services\ClickUpStatusMapper;
use App\Services\RequestStatusTransitionService;
use App\Support\ResolveServiceRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplyClickUpMapping
{
    public function __construct(
        private RequestStatusTransitionService $transitions,
        private NotifyEmployees $notifyEmployees,
        private ClickUpStatusMapper $statusMapper,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(ServiceRequest $request, array $payload): ServiceRequest
    {
        $eventUuid = (string) ($payload['event_uuid'] ?? $payload['event_id'] ?? '');
        $requestUuid = (string) ($payload['request_uuid'] ?? '');
        $taskType = ClickUpTaskType::fromDepartment((string) ($payload['task_type'] ?? ''));
        $integrationKey = (string) ($payload['integration_key'] ?? '');

        if ($eventUuid === '' || $requestUuid === '' || ! $taskType || $integrationKey === '') {
            throw ValidationException::withMessages([
                'payload' => 'event_uuid, request_uuid, task_type and integration_key are required.',
            ]);
        }

        if ($requestUuid !== $request->uuid) {
            throw ValidationException::withMessages([
                'request_uuid' => 'Request UUID mismatch.',
            ]);
        }

        return DB::transaction(function () use ($request, $payload, $taskType, $integrationKey): ServiceRequest {
            $briefId = isset($payload['brief_id']) ? (int) $payload['brief_id'] : null;
            if ($briefId === 0) {
                $briefId = null;
            }

            $employeeId = null;
            if (filled($payload['clickup_user_id'] ?? null)) {
                $employeeId = Employee::query()
                    ->where('clickup_user_id', (string) $payload['clickup_user_id'])
                    ->value('id');
            }

            ClickUpTask::query()->updateOrCreate(
                ['integration_key' => $integrationKey],
                [
                    'request_id' => $request->id,
                    'brief_id' => $briefId,
                    'task_type' => $taskType,
                    'clickup_task_id' => (string) ($payload['clickup_task_id'] ?? ''),
                    'clickup_list_id' => $payload['clickup_list_id'] ?? null,
                    'clickup_user_id' => $payload['clickup_user_id'] ?? null,
                    'employee_id' => $employeeId,
                    'clickup_url' => $payload['clickup_url'] ?? null,
                    'status' => $payload['status'] ?? null,
                ],
            );

            if ($taskType === ClickUpTaskType::Sales) {
                return $request->fresh(['clickupTasks', 'client']) ?? $request;
            }

            if (in_array($taskType, [ClickUpTaskType::Design, ClickUpTaskType::Content, ClickUpTaskType::Programming, ClickUpTaskType::Revision], true)) {
                $this->notifyTaskEmployees($request, $taskType, $payload);
            }

            if ($request->status === RequestStatus::PaymentConfirmed && $this->allRequiredTasksExist($request)) {
                $this->transitions->transition($request, RequestStatus::InProgress, 'system', 'All required ClickUp tasks mapped.');
            }

            if ($request->status === RequestStatus::RevisionRequested && $taskType === ClickUpTaskType::Revision) {
                $this->transitions->transition($request, RequestStatus::InProgress, 'system', 'Revision ClickUp task mapped.');
            }

            if (filled($payload['status'] ?? null)) {
                $request->forceFill([
                    'execution_status' => $this->statusMapper->fromClickUpStatus((string) $payload['status']),
                ])->save();
            }

            return $request->fresh(['clickupTasks', 'briefs', 'client']) ?? $request;
        });
    }

    private function allRequiredTasksExist(ServiceRequest $request): bool
    {
        $request->load('clickupTasks');
        if (! $request->work_type) {
            return false;
        }

        $existing = $request->clickupTasks
            ->pluck('task_type')
            ->map(fn (ClickUpTaskType $type) => $type->value)
            ->all();

        foreach ($request->work_type->requiredClickUpTaskTypes() as $required) {
            if (! in_array($required, $existing, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function notifyTaskEmployees(ServiceRequest $request, ClickUpTaskType $taskType, array $payload): void
    {
        $profession = match ($taskType) {
            ClickUpTaskType::Design => EmployeeProfession::Design,
            ClickUpTaskType::Content => EmployeeProfession::Content,
            ClickUpTaskType::Programming => EmployeeProfession::Web,
            ClickUpTaskType::Revision => EmployeeProfession::Design,
            default => null,
        };

        if (! $profession) {
            return;
        }

        $label = match ($taskType) {
            ClickUpTaskType::Design => 'مهمة تنفيذ (تصميم)',
            ClickUpTaskType::Content => 'مهمة تنفيذ (محتوى)',
            ClickUpTaskType::Programming => 'مهمة تنفيذ (برمجة)',
            ClickUpTaskType::Revision => 'طلب تعديل',
            default => 'مهمة',
        };

        $url = $payload['clickup_url'] ?? null;
        $displayNumber = ResolveServiceRequest::displayNumber($request);
        $text = "{$label}\n#{$displayNumber} — {$request->title}\n{$request->client?->name}\n\n{$request->description}";
        if ($url) {
            $text .= "\n\nClickUp: {$url}";
        }

        $this->notifyEmployees->handle($request, $profession, $text);
    }
}
