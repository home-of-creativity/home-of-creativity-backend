<?php

namespace App\Actions;

use App\Enums\ClickUpSyncEvent;
use App\Enums\RequestStatus;
use App\Enums\WorkflowEventType;
use App\Enums\ClickUpTaskType;
use App\Models\ClickUpTask;
use App\Models\DriveDelivery;
use App\Models\Revision;
use App\Models\ServiceRequest;
use App\Services\ClickUpClient;
use App\Services\RequestStatusTransitionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RequestRevision
{
    public function __construct(
        private RequestStatusTransitionService $transitions,
        private EnqueueIntegrationEvent $enqueueIntegrationEvent,
        private NotifyStaffDriveFile $notifyStaffDriveFile,
        private NotifyEmployees $notifyEmployees,
        private ClickUpClient $clickUp,
    ) {}

    public function handle(ServiceRequest $request, string $reason, ?DriveDelivery $delivery = null): ServiceRequest
    {
        if (! $request->allowsClientRevision()) {
            throw ValidationException::withMessages([
                'status' => 'طلب التعديل متاح بعد وصول ملفات العمل إلى البوت.',
            ]);
        }

        if ((int) $request->edit_rounds >= 1) {
            throw ValidationException::withMessages([
                'revision' => 'التعديل متاح مرة واحدة. للدعم اتصل +963 968 862 822 أو راسل info@hoc.agency.',
            ]);
        }

        $comment = $delivery !== null
            ? 'تعديل الصورة «'.((string) ($delivery->name ?: $delivery->drive_file_id)).'»: '.$reason
            : 'تعديل الطلب بالكامل: '.$reason;

        $updated = DB::transaction(function () use ($request, $reason, $delivery, $comment): ServiceRequest {
            $updated = $this->transitions->transition(
                $request,
                RequestStatus::RevisionRequested,
                'client',
                $comment,
            );

            Revision::query()->create([
                'request_id' => $updated->id,
                'comments' => $comment,
                'status' => 'open',
            ]);

            $updated->forceFill([
                'edit_rounds' => (int) $updated->edit_rounds + 1,
                'edit_estimate_hours' => 4,
            ])->save();

            $this->enqueueIntegrationEvent->handle(
                $updated->fresh(['client']) ?? $updated,
                WorkflowEventType::RevisionRequested,
                [
                    'reason' => $reason,
                    'scope' => $delivery !== null ? 'file' : 'request',
                    'drive_file_id' => $delivery?->drive_file_id,
                    'drive_file_name' => $delivery?->name,
                ],
            );

            return $updated;
        });

        $fresh = $updated->fresh(['client']) ?? $updated;
        $this->openRevisionTask($fresh, $comment);
        $notice = "طلب تعديل\n{$fresh->number}\n{$fresh->client?->name}: {$fresh->title}\nالتقدير: 4 ساعات\n\n{$comment}";
        if ($this->notifyEmployees->handleAdmins($notice) === 0) {
            $this->notifyStaffDriveFile->handle(
                $fresh,
                $notice,
                $delivery ?? $fresh->driveDeliveries()->whereNotNull('sent_at')->latest('id')->first(),
            );
        }

        app(SyncClickUpFromStaff::class)->handle($updated, ClickUpSyncEvent::Revision, null, $comment);

        return $updated;
    }

    private function openRevisionTask(ServiceRequest $request, string $comment): void
    {
        $listId = (string) config('services.clickup.lists.revision', '');
        $task = ClickUpTask::query()->updateOrCreate(
            ['integration_key' => 'revision:'.$request->id],
            [
                'request_id' => $request->id,
                'task_type' => ClickUpTaskType::Revision,
                'clickup_list_id' => $listId !== '' ? $listId : null,
                'status' => 'review',
                'planned_hours' => 4,
                'period_key' => 'revision',
            ],
        );
        if ($listId !== '' && $this->clickUp->configured() && ! filled($task->clickup_task_id)) {
            try {
                $created = $this->clickUp->createTasks($request->number, [[
                    'department' => 'revision',
                    'brief' => $comment,
                ]]);
                $remoteId = $created[0]['clickup_task_id'] ?? null;
                if (filled($remoteId)) {
                    $task->forceFill(['clickup_task_id' => (string) $remoteId])->save();
                }
            } catch (\Throwable $exception) {
                Log::warning('Revision ClickUp task failed.', [
                    'request' => $request->number,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
