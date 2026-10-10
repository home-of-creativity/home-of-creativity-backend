<?php

namespace App\Actions;

use App\Enums\ClickUpTaskType;
use App\Enums\EmployeeProfession;
use App\Models\ClickUpTask;
use App\Models\PhotographyBooking;
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

    public function handle(ServiceRequest $request, string $eventUuid, bool $notifyOnFailure = true): ServiceRequest
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
            $this->clearFailure($request);

            return $request;
        }

        if (! $this->clickUp->configured()) {
            Log::info('ClickUp is not configured; notifying execution teams only.', [
                'request' => $request->number,
            ]);
            if ($notifyOnFailure) {
                $this->notifyExecutionTeamsOnly($request);
            }

            return $request;
        }

        foreach ($this->missingPayloads($request, $briefPayloads) as $brief) {
            try {
                $created = $this->clickUp->createTasks($request->number, [[
                    'department' => $brief['department'],
                    'brief' => $brief['brief'],
                    'clickup_user_id' => $brief['clickup_user_id'] ?? null,
                    'due_at' => $brief['due_at'] ?? null,
                    'priority' => $brief['priority'] ?? null,
                    'hours' => $brief['hours'] ?? null,
                    'period_key' => data_get($request->work_plan, 'period_key'),
                    'employee_id' => $brief['employee_id'] ?? null,
                ]]);
            } catch (Throwable $exception) {
                Log::warning('ClickUp provisioning failed; the scheduler retries the missing tasks.', [
                    'request' => $request->number,
                    'department' => $brief['department'],
                    'error' => $exception->getMessage(),
                ]);
                $firstFailure = (int) $request->clickup_attempts === 0;
                $this->recordFailure($request, $exception->getMessage());
                if ($notifyOnFailure && $firstFailure) {
                    $this->notifyExecutionTeamsOnly($request);
                }

                return $request->fresh(['briefs', 'client', 'clickupTasks']) ?? $request;
            }

            $task = $created[0] ?? null;
            $taskType = ClickUpTaskType::fromDepartment((string) ($task['department'] ?? ''));
            if ($task === null || ! $taskType) {
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

        $this->clearFailure($request);

        return $request;
    }

    public function photographyTask(ServiceRequest $request, PhotographyBooking $booking): ?ClickUpTask
    {
        return ClickUpTask::query()
            ->where('request_id', $request->id)
            ->where('integration_key', $request->uuid.':photo:'.$booking->id)
            ->first();
    }

    /**
     * One task per agreed session, sized at the shoot hours and due at its start. A
     * moved session updates the same task. False only when ClickUp refused; the
     * agreed time stays either way.
     */
    public function syncPhotographySession(ServiceRequest $request, PhotographyBooking $booking, string $label): bool
    {
        if (! $this->clickUp->configured()) {
            return true;
        }
        $start = Carbon::parse($booking->starts_at?->format('Y-m-d H:i:s') ?? 'now', 'Asia/Damascus');
        $when = $start->format('Y-m-d H:i');
        $task = $this->photographyTask($request, $booking);
        $assignee = $booking->employee?->clickup_user_id;

        try {
            if ($task instanceof ClickUpTask && filled($task->clickup_task_id)) {
                $this->clickUp->updateTask((string) $task->clickup_task_id, null, $assignee, $start->getTimestamp() * 1000);
                $this->clickUp->addTaskComment((string) $task->clickup_task_id, 'موعد الجلسة صار '.$when.'. '.$label.'.');
                $task->forceFill(['employee_id' => $booking->employee_id ?? $task->employee_id])->save();

                return true;
            }

            $hours = BookPhotographySlot::SHOOT_HOURS;
            $created = $this->clickUp->createTasks($request->number, [[
                'department' => 'photography',
                'brief' => $label.'. جلسة تصوير متفق عليها يوم '.$when.'. الحضور '.$hours.' ساعات.',
                'hours' => $hours,
                'label' => $label.' · '.$when,
                'due_at' => $start->toIso8601String(),
                'clickup_user_id' => $assignee,
            ]]);
        } catch (Throwable $exception) {
            Log::warning('ClickUp photography session sync failed.', [
                'request' => $request->number,
                'booking' => $booking->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        $created = $created[0] ?? null;
        if ($created === null) {
            return false;
        }
        ClickUpTask::query()->updateOrCreate(
            ['integration_key' => $request->uuid.':photo:'.$booking->id],
            [
                'request_id' => $request->id,
                'task_type' => ClickUpTaskType::Photography,
                'clickup_task_id' => $created['clickup_task_id'],
                'clickup_list_id' => $this->clickUp->listIdForDepartment('photography'),
                'clickup_user_id' => $assignee,
                'employee_id' => $booking->employee_id,
                'clickup_url' => 'https://app.clickup.com/t/'.$created['clickup_task_id'],
                'status' => 'to do',
                'planned_hours' => BookPhotographySlot::SHOOT_HOURS,
                'period_key' => 'photo:'.$booking->id,
            ],
        );

        return true;
    }

    /** Closes the session task after the shoot or a cancellation. */
    public function closePhotographySession(ServiceRequest $request, PhotographyBooking $booking): bool
    {
        $task = $this->photographyTask($request, $booking);
        if (! $task instanceof ClickUpTask || in_array($task->status, ['complete', 'closed', 'done'], true)) {
            return true;
        }
        if ($this->clickUp->configured() && filled($task->clickup_task_id)) {
            try {
                $this->clickUp->updateTask((string) $task->clickup_task_id, (string) config('services.clickup.statuses.complete', 'complete'));
            } catch (Throwable $exception) {
                Log::warning('ClickUp photography close failed.', [
                    'request' => $request->number,
                    'booking' => $booking->id,
                    'error' => $exception->getMessage(),
                ]);

                return false;
            }
        }
        $task->forceFill(['status' => 'complete'])->save();

        return true;
    }

    /**
     * One payload per execution department that has no ClickUp task yet, so a
     * retry after a partial failure never creates a second task for a department.
     *
     * @param  list<array<string, mixed>>  $briefPayloads
     * @return list<array<string, mixed>>
     */
    private function missingPayloads(ServiceRequest $request, array $briefPayloads): array
    {
        $request->loadMissing('clickupTasks');
        $existing = $request->clickupTasks
            ->filter(fn ($task): bool => filled($task->clickup_task_id))
            ->map(fn ($task): string => $task->task_type->value)
            ->all();

        $missing = [];
        foreach ($briefPayloads as $brief) {
            $type = ClickUpTaskType::fromDepartment((string) ($brief['department'] ?? ''));
            if (! $type || $type === ClickUpTaskType::Sales || in_array($type->value, $existing, true)) {
                continue;
            }
            $existing[] = $type->value;
            $missing[] = $brief;
        }

        return $missing;
    }

    private function recordFailure(ServiceRequest $request, string $error): void
    {
        $request->forceFill([
            'clickup_error' => mb_substr($error, 0, 1000),
            'clickup_attempts' => (int) $request->clickup_attempts + 1,
            'clickup_failed_at' => now(),
        ])->save();
    }

    private function clearFailure(ServiceRequest $request): void
    {
        if ($request->clickup_error === null && (int) $request->clickup_attempts === 0) {
            return;
        }

        $request->forceFill([
            'clickup_error' => null,
            'clickup_attempts' => 0,
            'clickup_failed_at' => null,
        ])->save();
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
                if ($department === '' || $department === 'photography' || $brief === '') {
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
