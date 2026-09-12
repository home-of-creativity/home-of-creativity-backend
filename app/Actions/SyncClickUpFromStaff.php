<?php

namespace App\Actions;

use App\Enums\ClickUpSyncEvent;
use App\Enums\ClickUpTaskType;
use App\Enums\ExecutionStatus;
use App\Models\ClickUpTask;
use App\Models\Employee;
use App\Models\ServiceRequest;
use App\Services\ClickUpClient;
use App\Services\ClickUpStatusMapper;
use App\Support\ResolveServiceRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SyncClickUpFromStaff
{
    public function __construct(
        private ClickUpClient $clickUp,
        private ClickUpStatusMapper $statusMapper,
    ) {}

    public function handle(
        ServiceRequest $request,
        ClickUpSyncEvent $event,
        ?Employee $employee = null,
        ?string $detail = null,
    ): void {
        if (! filled(config('services.clickup.token'))) {
            return;
        }

        $request->loadMissing(['client', 'clickupTasks']);
        $tasks = $this->tasksFor($request, $event);
        if ($tasks->isEmpty()) {
            return;
        }

        $status = $this->statusMapper->clickUpStatusFor($event);
        $comment = $this->comment($request, $event, $employee, $detail);

        foreach ($tasks as $task) {
            $this->syncTask($task, $status, $employee, $comment);
        }

        $execution = $this->statusMapper->fromClickUpStatus($status);
        if ($event === ClickUpSyncEvent::Completed) {
            $execution = ExecutionStatus::Completed;
        }

        $request->forceFill(['execution_status' => $execution])->save();
    }

    /**
     * @return Collection<int, ClickUpTask>
     */
    private function tasksFor(ServiceRequest $request, ClickUpSyncEvent $event): Collection
    {
        $tasks = $request->clickupTasks
            ->filter(fn (ClickUpTask $task) => filled($task->clickup_task_id))
            ->values();

        $filtered = match ($event) {
            ClickUpSyncEvent::Contacted, ClickUpSyncEvent::Quotation => $tasks
                ->where('task_type', ClickUpTaskType::Sales)
                ->values(),
            ClickUpSyncEvent::Delivery, ClickUpSyncEvent::Revision => $tasks
                ->whereIn('task_type', [
                    ClickUpTaskType::Design,
                    ClickUpTaskType::Content,
                    ClickUpTaskType::Programming,
                    ClickUpTaskType::Revision,
                ])
                ->values(),
            ClickUpSyncEvent::Completed, ClickUpSyncEvent::Cancelled => $tasks,
        };

        return $filtered->isNotEmpty() ? $filtered : $tasks;
    }

    private function syncTask(ClickUpTask $task, string $status, ?Employee $employee, string $comment): void
    {
        $taskId = (string) $task->clickup_task_id;

        try {
            $this->clickUp->updateTask($taskId, $status, $employee?->clickup_user_id);
            $this->clickUp->addTaskComment($taskId, $comment);
        } catch (\Throwable $exception) {
            Log::warning('ClickUp staff sync failed.', [
                'task' => $taskId,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        $task->forceFill([
            'status' => $status,
            'employee_id' => $employee?->id ?? $task->employee_id,
            'clickup_user_id' => $employee?->clickup_user_id ?? $task->clickup_user_id,
        ])->save();
    }

    private function comment(
        ServiceRequest $request,
        ClickUpSyncEvent $event,
        ?Employee $employee,
        ?string $detail,
    ): string {
        $displayNumber = ResolveServiceRequest::displayNumber($request);
        $who = $employee?->name ?? 'النظام';

        $line = match ($event) {
            ClickUpSyncEvent::Contacted => "تم التواصل مع الزبون بواسطة {$who}. الحالة في ClickUp: قيد التنفيذ.",
            ClickUpSyncEvent::Quotation => "أرسل {$who} عرض سعر للزبون.",
            ClickUpSyncEvent::Delivery => "سلّم {$who} العمل. الحالة: مراجعة.",
            ClickUpSyncEvent::Revision => 'طلب الزبون تعديلاً. أُعيدت المهمة إلى قيد التنفيذ.',
            ClickUpSyncEvent::Completed => 'تم إنجاز الطلب واعتماد التسليم.',
            ClickUpSyncEvent::Cancelled => 'أُلغي الطلب.',
        };

        $parts = ["طلب #{$displayNumber}", $line];
        if (filled($detail)) {
            $parts[] = $detail;
        }

        return implode("\n", $parts);
    }
}
