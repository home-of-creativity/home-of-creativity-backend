<?php

namespace App\Actions;

use App\Enums\ClickUpTaskType;
use App\Models\ClickUpTask;
use App\Models\RequestFile;
use App\Models\ServiceRequest;
use App\Services\ClickUpClient;
use App\Support\ResolveServiceRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProvisionSalesClickUpTask
{
    public function __construct(
        private ClickUpClient $clickUp,
        private ApplyClickUpMapping $applyClickUpMapping,
    ) {}

    public function handle(ServiceRequest $request): ServiceRequest
    {
        $request->loadMissing(['client', 'files', 'clickupTasks']);

        if ($this->salesTaskExists($request)) {
            return $request;
        }

        if (! $this->clickUp->configured()) {
            Log::info('ClickUp sales intake skipped; ClickUp is not configured.', [
                'request' => $request->number,
            ]);

            return $request;
        }

        try {
            $description = $this->buildDescription($request);
            $taskId = $this->clickUp->createSalesIntakeTask(
                $request->number,
                $request->title,
                $description,
            );

            foreach ($request->files->where('kind', 'brief_attachment') as $file) {
                $this->attachFileToTask($taskId, $file);
            }

            $eventUuid = (string) Str::uuid();

            return $this->applyClickUpMapping->handle($request, [
                'event_uuid' => $eventUuid,
                'request_uuid' => $request->uuid,
                'task_type' => ClickUpTaskType::Sales->value,
                'integration_key' => ClickUpTask::buildIntegrationKey($request->uuid, null, ClickUpTaskType::Sales),
                'clickup_task_id' => $taskId,
                'clickup_list_id' => $this->clickUp->listIdForDepartment('sales'),
                'clickup_url' => 'https://app.clickup.com/t/'.$taskId,
                'status' => 'to do',
            ]);
        } catch (\Throwable $exception) {
            Log::warning('ClickUp sales intake failed.', [
                'request' => $request->number,
                'error' => $exception->getMessage(),
            ]);

            return $request;
        }
    }

    public function appendReceipt(ServiceRequest $request, RequestFile $receipt): void
    {
        $request->loadMissing('clickupTasks');
        $salesTask = $request->clickupTasks
            ->first(fn ($task) => $task->task_type === ClickUpTaskType::Sales);

        if (! $salesTask || ! filled($salesTask->clickup_task_id) || ! $this->clickUp->configured()) {
            return;
        }

        try {
            $displayNumber = ResolveServiceRequest::displayNumber($request);
            $this->clickUp->addTaskComment(
                (string) $salesTask->clickup_task_id,
                "📎 رفع الزبون وصل دفع للطلب #{$displayNumber}: {$receipt->original_name}",
            );
            $this->attachFileToTask((string) $salesTask->clickup_task_id, $receipt);
        } catch (\Throwable $exception) {
            Log::warning('ClickUp sales receipt sync failed.', [
                'request' => $request->number,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /** The sales task takes the request's edited title and description. */
    public function syncEditedRequest(ServiceRequest $request): void
    {
        $request->loadMissing(['client', 'files', 'clickupTasks']);
        $salesTask = $request->clickupTasks
            ->first(fn ($task) => $task->task_type === ClickUpTaskType::Sales);
        if (! $salesTask || ! filled($salesTask->clickup_task_id) || ! $this->clickUp->configured()) {
            return;
        }

        try {
            $this->clickUp->renameTask(
                (string) $salesTask->clickup_task_id,
                $request->number.' · '.$request->title,
                $this->buildDescription($request),
            );
            $this->clickUp->addTaskComment((string) $salesTask->clickup_task_id, 'عدّل الزبون عنوان الطلب ووصفه.');
        } catch (\Throwable $exception) {
            Log::warning('ClickUp sales task did not take the edited request.', [
                'request' => $request->number,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function salesTaskExists(ServiceRequest $request): bool
    {
        return $request->clickupTasks
            ->contains(fn ($task) => $task->task_type === ClickUpTaskType::Sales);
    }

    private function buildDescription(ServiceRequest $request): string
    {
        $displayNumber = ResolveServiceRequest::displayNumber($request);
        $lines = [
            "طلب #{$displayNumber}",
            "العنوان: {$request->title}",
            "الزبون: {$request->client?->name}",
            "Telegram: {$request->client?->telegram_user_id}",
            '',
            'الوصف:',
            $request->description,
        ];

        $attachments = $request->files->where('kind', 'brief_attachment');
        if ($attachments->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'مرفقات الزبون:';
            foreach ($attachments as $file) {
                $lines[] = '• '.$file->original_name;
            }
        }

        return implode("\n", $lines);
    }

    private function attachFileToTask(string $taskId, RequestFile $file): void
    {
        if (! filled($file->path) || ! Storage::disk('local')->exists($file->path)) {
            return;
        }

        $this->clickUp->addTaskAttachment(
            $taskId,
            Storage::disk('local')->path($file->path),
            $file->original_name,
        );
    }
}
