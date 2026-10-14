<?php

namespace App\Actions;

use App\Enums\ClickUpTaskType;
use App\Enums\EmployeeProfession;
use App\Enums\RequestStatus;
use App\Models\ClickUpTask;
use App\Models\Employee;
use App\Models\ServiceRequest;
use App\Services\ClickUpClient;
use App\Support\WorkCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * «تغيير الخطة»: the plan approved for a request, edited by department. Every ClickUp
 * department is offered with its staff; a department kept is updated in ClickUp (brief,
 * hours, due date, priority, assignee), one added gets a task, and one removed has its task
 * closed. Whether the request has photography, and how many sessions, is set here too.
 */
class ChangeWorkPlan
{
    /** Departments that become ClickUp tasks; photography is booked by session instead. */
    public const DEPARTMENTS = ['design', 'content', 'programming'];

    private const PROFESSIONS = [
        'design' => EmployeeProfession::Design,
        'content' => EmployeeProfession::Content,
        'programming' => EmployeeProfession::Web,
    ];

    private const LABELS = [
        'design' => 'تصميم',
        'content' => 'محتوى',
        'programming' => 'برمجة',
    ];

    private const PRIORITY_LABELS = [1 => 'عاجلة', 2 => 'عالية', 3 => 'عادية', 4 => 'منخفضة'];

    private const FINISHED = ['complete', 'completed', 'closed', 'done'];

    public function __construct(
        private ClickUpClient $clickUp,
        private ProvisionClickUpTasks $provision,
        private PhotographySessions $sessions,
        private WorkCalendar $calendar,
    ) {}

    /**
     * The plan as the dashboard edits it: every department with its staff, the departments
     * in the approved plan, their ClickUp tasks, and the photography sessions.
     *
     * @return array<string, mixed>
     */
    public function current(ServiceRequest $request): array
    {
        $request->loadMissing('clickupTasks');
        $operations = collect(data_get($request->work_plan, 'operations', []))
            ->filter(fn (mixed $row): bool => is_array($row))
            ->keyBy(fn (array $row): string => (string) ($row['department'] ?? ''));

        $departments = [];
        foreach (self::DEPARTMENTS as $department) {
            $operation = $operations->get($department);
            $task = $this->taskFor($request, $department);
            $departments[] = [
                'department' => $department,
                'label' => self::LABELS[$department],
                'included' => $operation !== null,
                'brief' => (string) ($operation['brief'] ?? ''),
                'hours' => (int) ($operation['hours'] ?? 0),
                'priority' => (int) ($operation['priority'] ?? 3),
                'employee_id' => $operation['employee_id'] ?? null,
                'due_at' => $operation['due_at'] ?? null,
                'task' => $task ? ['url' => $task->clickup_url, 'status' => $task->status] : null,
                'employees' => Employee::query()
                    ->approved()
                    ->where('profession', self::PROFESSIONS[$department])
                    ->orderBy('name')
                    ->get(['id', 'name', 'clickup_user_id'])
                    ->map(fn (Employee $employee): array => [
                        'id' => $employee->id,
                        'name' => $employee->name,
                        'in_clickup' => filled($employee->clickup_user_id),
                    ])
                    ->all(),
            ];
        }

        return [
            'confirmed' => $request->plan_confirmed_at !== null,
            'departments' => $departments,
            'photography' => [
                'enabled' => (int) ($request->photography_sessions ?? 0) > 0,
                'sessions' => (int) ($request->photography_sessions ?? 0),
                'used' => $this->sessions->used($request),
            ],
            'client_due_at' => $request->client_due_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<array{department: string, brief: string, hours: int, priority?: int|null, employee_id?: int|null}>  $lines
     * @return array{request: ServiceRequest, errors: list<string>}
     */
    public function handle(ServiceRequest $request, array $lines, int $photographySessions, string $actor): array
    {
        if ($request->paid_at === null && ! in_array($request->status, [RequestStatus::PaymentConfirmed, RequestStatus::InProgress, RequestStatus::RevisionRequested, RequestStatus::ReadyForReview, RequestStatus::Completed], true)) {
            throw ValidationException::withMessages(['plan' => 'الخطة تُعدّل بعد تأكيد الدفع.']);
        }
        if ($lines === [] && $photographySessions <= 0) {
            throw ValidationException::withMessages(['plan' => 'اختر قسماً واحداً على الأقل أو جلسات تصوير.']);
        }
        if ($photographySessions < $this->sessions->used($request)) {
            throw ValidationException::withMessages(['photography' => 'عدد الجلسات أقل من الجلسات التي تمت.']);
        }

        $start = Carbon::parse($request->plan_confirmed_at ?? now());
        $operations = [];
        foreach ($lines as $line) {
            $department = (string) $line['department'];
            $hours = max(1, (int) $line['hours']);
            $priority = max(1, min(4, (int) ($line['priority'] ?? 3)));
            $employee = filled($line['employee_id'] ?? null)
                ? Employee::query()->approved()->where('profession', self::PROFESSIONS[$department])->find((int) $line['employee_id'])
                : null;
            $operations[] = [
                'department' => $department,
                'brief' => trim((string) $line['brief']),
                'hours' => $hours,
                'priority' => $priority,
                'priority_label' => self::PRIORITY_LABELS[$priority],
                'due_at' => $this->calendar->addWorkHours($start, $hours)->toIso8601String(),
                'employee_id' => $employee?->id,
                'employee_name' => $employee?->name,
                'clickup_user_id' => $employee?->clickup_user_id,
            ];
        }
        $longest = (int) collect($operations)->max('hours');

        $request->forceFill([
            'work_plan' => array_merge(is_array($request->work_plan) ? $request->work_plan : [], [
                'source' => 'manual',
                'changed_at' => now()->toIso8601String(),
                'changed_by' => $actor,
                'operations' => $operations,
            ]),
            'client_due_at' => $longest > 0 ? $this->calendar->addWorkHours($start, $longest) : $request->client_due_at,
        ])->save();
        $updated = $this->sessions->setCap($request->fresh() ?? $request, $photographySessions);

        $errors = $this->syncClickUp($updated->fresh(['briefs', 'client', 'clickupTasks']) ?? $updated, $operations);
        $updated = $updated->fresh() ?? $updated;
        if ($updated->plan_confirmed_at === null) {
            $updated->forceFill(['plan_confirmed_at' => now()])->save();
        }

        return ['request' => $updated->fresh(['client', 'clickupTasks', 'pricingPackage']) ?? $updated, 'errors' => $errors];
    }

    /**
     * @param  list<array<string, mixed>>  $operations
     * @return list<string> what ClickUp refused
     */
    private function syncClickUp(ServiceRequest $request, array $operations): array
    {
        if (! $this->clickUp->configured()) {
            return [];
        }
        $errors = [];

        // Departments without a task yet get one, the same way a confirmed plan does.
        $request = $this->provision->handle($request, (string) Str::uuid(), notifyOnFailure: false);
        if (filled($request->clickup_error)) {
            $errors[] = (string) $request->clickup_error;
        }
        $request->load('clickupTasks');

        $kept = [];
        foreach ($operations as $operation) {
            $department = (string) $operation['department'];
            $kept[] = $department;
            $task = $this->taskFor($request, $department);
            if ($task === null || ! filled($task->clickup_task_id)) {
                continue;
            }
            $previous = $task->clickup_user_id;
            try {
                $this->clickUp->updateTaskDetails(
                    (string) $task->clickup_task_id,
                    (string) $operation['brief'],
                    (int) $operation['hours'],
                    Carbon::parse((string) $operation['due_at'])->getTimestampMs(),
                    (int) $operation['priority'],
                    filled($operation['clickup_user_id']) ? (string) $operation['clickup_user_id'] : null,
                    filled($previous) ? (string) $previous : null,
                );
            } catch (Throwable $exception) {
                Log::warning('ClickUp task did not take the changed plan.', ['task' => $task->clickup_task_id, 'error' => $exception->getMessage()]);
                $errors[] = self::LABELS[$department].': '.$exception->getMessage();
            }
            $task->forceFill([
                'planned_hours' => (int) $operation['hours'],
                'employee_id' => $operation['employee_id'] ?? $task->employee_id,
                'clickup_user_id' => $operation['clickup_user_id'] ?? $task->clickup_user_id,
            ])->save();
        }

        // A department taken out of the plan closes its open task.
        foreach (self::DEPARTMENTS as $department) {
            if (in_array($department, $kept, true)) {
                continue;
            }
            $task = $this->taskFor($request, $department);
            if ($task === null || ! filled($task->clickup_task_id) || in_array(strtolower((string) $task->status), self::FINISHED, true)) {
                continue;
            }
            $closed = (string) config('services.clickup.statuses.complete', 'complete');
            try {
                $this->clickUp->updateTask((string) $task->clickup_task_id, $closed);
                $this->clickUp->addTaskComment((string) $task->clickup_task_id, 'أُزيل هذا القسم من خطة الطلب.');
                $task->forceFill(['status' => $closed])->save();
            } catch (Throwable $exception) {
                Log::warning('ClickUp task of a removed department stayed open.', ['task' => $task->clickup_task_id, 'error' => $exception->getMessage()]);
                $errors[] = self::LABELS[$department].': '.$exception->getMessage();
            }
        }

        return $errors;
    }

    private function taskFor(ServiceRequest $request, string $department): ?ClickUpTask
    {
        $type = ClickUpTaskType::fromDepartment($department);

        return $request->clickupTasks
            ->filter(fn (ClickUpTask $task): bool => $task->task_type === $type)
            ->sortByDesc('id')
            ->first();
    }
}
