<?php

namespace App\Actions;

use App\Enums\ClickUpTaskType;
use App\Enums\EmployeeProfession;
use App\Models\ServiceRequest;
use App\Services\ClickUpClient;
use App\Services\GoogleTranslateService;
use App\Support\ResolveServiceRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProvisionClickUpTasks
{
    public function __construct(
        private ClickUpClient $clickUp,
        private ApplyClickUpMapping $applyClickUpMapping,
        private NotifyEmployees $notifyEmployees,
        private GoogleTranslateService $translator,
    ) {}

    public function handle(ServiceRequest $request, string $eventUuid): ServiceRequest
    {
        $request->loadMissing(['briefs', 'client', 'clickupTasks']);

        $briefPayloads = $this->briefPayloads($request);
        if ($briefPayloads === []) {
            return $request;
        }

        $allowNewPeriod = (bool) data_get($request->work_plan, 'allow_new_period');
        if ($this->executionTasksExist($request)) {
            if ($allowNewPeriod) {
                $this->updateExistingTasks($request, $briefPayloads);
            }

            return $request;
        }

        if ($this->clickUp->configured()) {
            try {
                $created = $this->clickUp->createTasks(
                    $request->number,
                    array_map(fn (array $brief): array => [
                        'department' => $brief['department'],
                        'brief' => $brief['brief'],
                        'clickup_user_id' => $brief['clickup_user_id'] ?? null,
                        'due_at' => $brief['due_at'] ?? null,
                        'priority' => $brief['priority'] ?? null,
                        'hours' => $brief['hours'] ?? null,
                        'period_key' => data_get($request->work_plan, 'period_key'),
                        'employee_id' => $brief['employee_id'] ?? null,
                    ], $briefPayloads),
                );

                foreach ($created as $index => $task) {
                    $brief = $briefPayloads[$index] ?? null;
                    $taskType = ClickUpTaskType::fromDepartment($task['department']);
                    if (! $taskType || $taskType === ClickUpTaskType::Sales) {
                        continue;
                    }

                    $request = $this->applyClickUpMapping->handle($request, [
                        'event_uuid' => $eventUuid,
                        'request_uuid' => $request->uuid,
                        'task_type' => $taskType->value,
                        'integration_key' => "{$eventUuid}:{$taskType->value}",
                        'clickup_task_id' => $task['clickup_task_id'],
                        'clickup_list_id' => $this->clickUp->listIdForDepartment($task['department']),
                        'clickup_url' => 'https://app.clickup.com/t/'.$task['clickup_task_id'],
                        'brief_id' => $brief['id'] ?? null,
                        'clickup_user_id' => $brief['clickup_user_id'] ?? null,
                        'status' => 'to do',
                        'planned_hours' => $brief['hours'] ?? null,
                        'period_key' => data_get($request->work_plan, 'period_key', 'initial'),
                    ]);
                }

                return $request;
            } catch (\Throwable $exception) {
                Log::warning('ClickUp provisioning failed; notifying teams without tasks.', [
                    'request' => $request->number,
                    'error' => $exception->getMessage(),
                ]);
            }
        } else {
            Log::info('ClickUp is not configured; notifying execution teams only.', [
                'request' => $request->number,
            ]);
        }

        $this->notifyExecutionTeamsOnly($request);

        return $request;
    }

    /**
     * @return list<array{id: int|null, department: string, brief: string, clickup_user_id?: string|null, due_at?: string|null, priority?: int|null}>
     */
    private function briefPayloads(ServiceRequest $request): array
    {
        $operations = data_get($request->work_plan, 'operations');
        if (is_array($operations) && $operations !== []) {
            $payloads = [];
            foreach ($operations as $operation) {
                if (! is_array($operation)) {
                    continue;
                }
                $department = (string) ($operation['department'] ?? '');
                $brief = $this->translator->toArabic((string) ($operation['brief'] ?? ''));
                if ($department === '' || $brief === '') {
                    continue;
                }
                $briefId = $request->briefs->first(
                    fn ($item): bool => (string) ($item->type ?? $item->department) === $department,
                )?->id;
                $payloads[] = [
                    'id' => $briefId ? (int) $briefId : null,
                    'department' => $department,
                    'brief' => $brief,
                    'clickup_user_id' => $operation['clickup_user_id'] ?? null,
                    'due_at' => $operation['due_at'] ?? null,
                    'priority' => isset($operation['priority']) ? (int) $operation['priority'] : null,
                    'hours' => isset($operation['hours']) ? (int) $operation['hours'] : null,
                    'employee_id' => $operation['employee_id'] ?? null,
                ];
            }

            if ($payloads !== []) {
                return $payloads;
            }
        }

        return $request->briefs
            ->sortBy('id')
            ->values()
            ->map(fn ($brief): array => [
                'id' => (int) $brief->id,
                'department' => (string) ($brief->type ?? $brief->department),
                'brief' => $this->translator->toArabic((string) $brief->brief),
            ])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $briefPayloads
     */
    private function updateExistingTasks(ServiceRequest $request, array $briefPayloads): void
    {
        $request->loadMissing('clickupTasks');
        foreach ($briefPayloads as $brief) {
            $taskType = ClickUpTaskType::fromDepartment((string) ($brief['department'] ?? ''));
            if (! $taskType) {
                continue;
            }
            $task = $request->clickupTasks->first(
                fn ($row): bool => $row->task_type === $taskType,
            );
            if ($task === null) {
                continue;
            }
            $task->forceFill([
                'planned_hours' => $brief['hours'] ?? $task->planned_hours,
                'employee_id' => $brief['employee_id'] ?? $task->employee_id,
                'clickup_user_id' => $brief['clickup_user_id'] ?? $task->clickup_user_id,
                'period_key' => (string) data_get($request->work_plan, 'period_key', $task->period_key),
            ])->save();

            if (! $this->clickUp->configured() || ! filled($task->clickup_task_id)) {
                continue;
            }
            try {
                $due = filled($brief['due_at'] ?? null)
                    ? Carbon::parse((string) $brief['due_at'])->getTimestampMs()
                    : null;
                $this->clickUp->updateTask(
                    (string) $task->clickup_task_id,
                    null,
                    filled($brief['clickup_user_id'] ?? null) ? (string) $brief['clickup_user_id'] : null,
                    $due,
                    isset($brief['priority']) ? (int) $brief['priority'] : null,
                );
            } catch (Throwable $exception) {
                Log::warning('ClickUp task update failed.', [
                    'task' => $task->clickup_task_id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function executionTasksExist(ServiceRequest $request): bool
    {
        $required = $request->plannedDepartments();
        if ($required === []) {
            return false;
        }

        $existing = $request->clickupTasks
            ->pluck('task_type')
            ->map(fn (ClickUpTaskType $type) => $type->value)
            ->all();

        foreach ($required as $department) {
            if (! in_array($department, $existing, true)) {
                return false;
            }
        }

        return true;
    }

    private function notifyExecutionTeamsOnly(ServiceRequest $request): void
    {
        foreach ($request->briefs as $brief) {
            $taskType = ClickUpTaskType::fromDepartment((string) ($brief->type ?? $brief->department));
            if (! in_array($taskType, [ClickUpTaskType::Design, ClickUpTaskType::Content, ClickUpTaskType::Programming, ClickUpTaskType::Photography], true)) {
                continue;
            }

            $profession = match ($taskType) {
                ClickUpTaskType::Design => EmployeeProfession::Design,
                ClickUpTaskType::Content => EmployeeProfession::Content,
                ClickUpTaskType::Programming => EmployeeProfession::Web,
                ClickUpTaskType::Photography => EmployeeProfession::Media,
                default => null,
            };

            if (! $profession) {
                continue;
            }

            $label = match ($taskType) {
                ClickUpTaskType::Design => 'مهمة تنفيذ (تصميم)',
                ClickUpTaskType::Content => 'مهمة تنفيذ (محتوى)',
                ClickUpTaskType::Programming => 'مهمة تنفيذ (برمجة)',
                ClickUpTaskType::Photography => 'مهمة تنفيذ (تصوير)',
                default => 'مهمة تنفيذ',
            };
            $displayNumber = ResolveServiceRequest::displayNumber($request);
            $arabicBrief = $this->translator->toArabic((string) $brief->brief);
            $text = "{$label}\n#{$displayNumber} — {$request->title}\n{$request->client?->name}\n\n{$arabicBrief}";

            $this->notifyEmployees->handle($request, $profession, $text);
        }
    }
}
