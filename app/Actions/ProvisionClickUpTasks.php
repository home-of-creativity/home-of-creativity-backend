<?php

namespace App\Actions;

use App\Enums\ClickUpTaskType;
use App\Enums\EmployeeProfession;
use App\Models\ServiceRequest;
use App\Services\ClickUpClient;
use App\Support\ResolveServiceRequest;
use Illuminate\Support\Facades\Log;

class ProvisionClickUpTasks
{
    public function __construct(
        private ClickUpClient $clickUp,
        private ApplyClickUpMapping $applyClickUpMapping,
        private NotifyEmployees $notifyEmployees,
    ) {}

    public function handle(ServiceRequest $request, string $eventUuid): ServiceRequest
    {
        $request->loadMissing(['briefs', 'client', 'clickupTasks']);

        if ($request->briefs->isEmpty() || ! $request->work_type) {
            return $request;
        }

        if ($this->executionTasksExist($request)) {
            return $request;
        }

        /** @var list<array{id: int, department: string, brief: string}> $briefPayloads */
        $briefPayloads = $request->briefs
            ->sortBy('id')
            ->values()
            ->map(fn ($brief): array => [
                'id' => (int) $brief->id,
                'department' => (string) ($brief->type ?? $brief->department),
                'brief' => (string) $brief->brief,
            ])
            ->all();

        if ($this->clickUp->configured()) {
            try {
                $created = $this->clickUp->createTasks(
                    $request->number,
                    array_map(fn (array $brief): array => [
                        'department' => $brief['department'],
                        'brief' => $brief['brief'],
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
                        'status' => 'to do',
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

    private function executionTasksExist(ServiceRequest $request): bool
    {
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

    private function notifyExecutionTeamsOnly(ServiceRequest $request): void
    {
        foreach ($request->briefs as $brief) {
            $taskType = ClickUpTaskType::fromDepartment((string) ($brief->type ?? $brief->department));
            if (! in_array($taskType, [ClickUpTaskType::Design, ClickUpTaskType::Content, ClickUpTaskType::Programming], true)) {
                continue;
            }

            $profession = match ($taskType) {
                ClickUpTaskType::Design => EmployeeProfession::Design,
                ClickUpTaskType::Content => EmployeeProfession::Content,
                ClickUpTaskType::Programming => EmployeeProfession::Web,
                default => null,
            };

            if (! $profession) {
                continue;
            }

            $label = match ($taskType) {
                ClickUpTaskType::Design => 'مهمة تنفيذ (تصميم)',
                ClickUpTaskType::Content => 'مهمة تنفيذ (محتوى)',
                ClickUpTaskType::Programming => 'مهمة تنفيذ (برمجة)',
                default => 'مهمة تنفيذ',
            };
            $displayNumber = ResolveServiceRequest::displayNumber($request);
            $text = "{$label}\n#{$displayNumber} — {$request->title}\n{$request->client?->name}\n\n{$brief->brief}";

            $this->notifyEmployees->handle($request, $profession, $text);
        }
    }
}
