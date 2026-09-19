<?php

namespace App\Actions;

use App\Enums\ClickUpTaskType;
use App\Enums\EmployeeProfession;
use App\Models\ServiceRequest;
use App\Services\ClickUpClient;
use App\Services\GoogleTranslateService;
use App\Support\ResolveServiceRequest;
use Illuminate\Support\Facades\Log;

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

        if ($this->executionTasksExist($request)) {
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
