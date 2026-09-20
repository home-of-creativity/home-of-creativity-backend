<?php

namespace App\Actions;

use App\Enums\EmployeeProfession;
use App\Enums\RequestSource;
use App\Enums\RequestStatus;
use App\Enums\WorkflowEventType;
use App\Models\Client;
use App\Models\RequestStatusHistory;
use App\Models\ServiceRequest;
use App\Support\ResolveServiceRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SubmitServiceRequest
{
    public function __construct(
        private GenerateRequestNumber $generateRequestNumber,
        private EnqueueIntegrationEvent $enqueueIntegrationEvent,
        private NotifyEmployees $notifyEmployees,
        private StoreRequestAttachments $storeRequestAttachments,
        private ProvisionSalesClickUpTask $provisionSalesClickUpTask,
        private EnsureRequestDriveFolder $ensureRequestDriveFolder,
    ) {}

    /**
     * @param  array{
     *     title: string,
     *     description: string,
     *     source?: RequestSource,
     *     attachments?: list<array{file_name: string, file_base64: string, mime_type?: string|null}>
     * }  $data
     */
    public function handle(Client $client, array $data): ServiceRequest
    {
        $fresh = DB::transaction(function () use ($client, $data): ServiceRequest {
            $request = $client->requests()->create([
                'number' => $this->generateRequestNumber->handle(),
                'title' => $data['title'],
                'description' => $data['description'],
                'status' => RequestStatus::Submitted,
                'source' => $data['source'] ?? RequestSource::Website,
            ]);

            RequestStatusHistory::query()->create([
                'request_id' => $request->id,
                'from_status' => null,
                'to_status' => RequestStatus::Submitted->value,
                'actor' => 'client',
                'note' => 'Request submitted.',
            ]);

            if (! empty($data['attachments'])) {
                $this->storeRequestAttachments->handle($request, $data['attachments']);
            }

            $fresh = $request->fresh(['client', 'files']) ?? $request;

            $displayNumber = ResolveServiceRequest::displayNumber($fresh);
            $attachmentCount = $fresh->files?->count() ?? 0;
            $attachmentLine = $attachmentCount > 0
                ? "\n📎 مرفقات: {$attachmentCount}"
                : '';

            $this->notifyEmployees->handle(
                $fresh,
                EmployeeProfession::Sales,
                "طلب جديد لقسم المبيعات\n#{$displayNumber} — {$fresh->title}\n{$fresh->client?->name}\n\n{$fresh->description}{$attachmentLine}\n\nللرد: /reply",
                [
                    ['text' => "💬 رد على #{$displayNumber}", 'callback_data' => "rsel:{$displayNumber}"],
                ],
            );

            $this->notifySalesAttachments($fresh, $displayNumber);

            $fresh = $this->provisionSalesClickUpTask->handle($fresh)->fresh(['client', 'files', 'clickupTasks']) ?? $fresh;

            $this->enqueueIntegrationEvent->handle(
                $fresh,
                WorkflowEventType::RequestSubmitted,
            );

            return $fresh->fresh(['client', 'events', 'files', 'clickupTasks']) ?? $fresh;
        });

        return $this->ensureRequestDriveFolder->handleQuietly($fresh);
    }

    private function notifySalesAttachments(ServiceRequest $request, string $displayNumber): void
    {
        foreach ($request->files->where('kind', 'brief_attachment') as $file) {
            if (! filled($file->path)) {
                continue;
            }

            $path = Storage::disk('local')->path($file->path);
            if (! is_file($path)) {
                continue;
            }

            $this->notifyEmployees->handle(
                $request,
                EmployeeProfession::Sales,
                "📎 مرفق من الزبون — #{$displayNumber}\n{$file->original_name}",
                null,
                [
                    'path' => $path,
                    'mime' => $this->guessMimeType($file->original_name),
                    'name' => $file->original_name,
                ],
            );
        }
    }

    private function guessMimeType(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }
}
