<?php

namespace App\Actions;

use App\Enums\ClickUpSyncEvent;
use App\Enums\RequestStatus;
use App\Enums\WorkflowEventType;
use App\Models\DriveDelivery;
use App\Models\Revision;
use App\Models\ServiceRequest;
use App\Services\RequestStatusTransitionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestRevision
{
    public function __construct(
        private RequestStatusTransitionService $transitions,
        private EnqueueIntegrationEvent $enqueueIntegrationEvent,
        private NotifyStaffDriveFile $notifyStaffDriveFile,
    ) {}

    public function handle(ServiceRequest $request, string $reason, ?DriveDelivery $delivery = null): ServiceRequest
    {
        if (! $request->allowsClientRevision()) {
            throw ValidationException::withMessages([
                'status' => 'طلب التعديل متاح بعد وصول ملفات العمل إلى البوت.',
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
        $this->notifyStaffDriveFile->handle(
            $fresh,
            "طلب تعديل\n{$fresh->number}\n{$fresh->client?->name}: {$fresh->title}\n\n{$comment}",
            $delivery ?? $fresh->driveDeliveries()->whereNotNull('sent_at')->latest('id')->first(),
        );

        app(SyncClickUpFromStaff::class)->handle($updated, ClickUpSyncEvent::Revision, null, $comment);

        return $updated;
    }
}
