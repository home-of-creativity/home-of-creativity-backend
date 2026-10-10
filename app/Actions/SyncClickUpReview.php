<?php

namespace App\Actions;

use App\Models\ServiceRequest;
use App\Services\ClickUpClient;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncClickUpReview
{
    public function __construct(private ClickUpClient $clickUp) {}

    public function markDelivered(ServiceRequest $request): void
    {
        $this->move($request, (string) config('services.clickup.statuses.review', 'review'), 'review');
    }

    public function markApproved(ServiceRequest $request): void
    {
        $this->move($request, (string) config('services.clickup.statuses.complete', 'complete'), 'complete');
    }

    private function move(ServiceRequest $request, string $clickUpStatus, string $localStatus): void
    {
        $request->loadMissing('clickupTasks');
        foreach ($request->clickupTasks as $task) {
            if (! filled($task->clickup_task_id) || in_array($task->status, ['complete', 'closed', 'done'], true)) {
                continue;
            }
            // A photography session task follows its own shoot, not the file delivery.
            if (str_contains((string) $task->integration_key, ':photo:')) {
                continue;
            }
            if ($localStatus === 'complete' && $task->status !== 'review') {
                continue;
            }
            $revisionList = (string) config('services.clickup.lists.revision', '');
            if ($this->clickUp->configured()) {
                try {
                    $this->clickUp->updateTask((string) $task->clickup_task_id, $clickUpStatus);
                    if ($localStatus === 'review' && $revisionList !== '') {
                        $this->clickUp->addTaskToList((string) $task->clickup_task_id, $revisionList);
                    }
                } catch (Throwable $exception) {
                    Log::warning('ClickUp review sync failed.', [
                        'task' => $task->clickup_task_id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
            $task->forceFill([
                'status' => $localStatus,
                'clickup_list_id' => $localStatus === 'review' && $revisionList !== ''
                    ? $revisionList
                    : $task->clickup_list_id,
            ])->save();
        }
    }
}
