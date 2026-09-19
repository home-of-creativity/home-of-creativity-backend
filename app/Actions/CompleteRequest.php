<?php

namespace App\Actions;

use App\Enums\ClickUpSyncEvent;
use App\Enums\RequestStatus;
use App\Enums\WorkflowEventType;
use App\Models\Employee;
use App\Models\ServiceRequest;
use App\Services\RequestStatusTransitionService;
use Illuminate\Support\Facades\DB;

class CompleteRequest
{
    public function __construct(
        private RequestStatusTransitionService $transitions,
        private EnqueueIntegrationEvent $enqueueIntegrationEvent,
        private SyncClickUpFromStaff $syncClickUp,
        private ClearDriveDeliveryKeyboards $clearDriveDeliveryKeyboards,
    ) {}

    public function handle(ServiceRequest $request, ?string $actor = 'admin', ?Employee $employee = null): ServiceRequest
    {
        $updated = DB::transaction(function () use ($request, $actor): ServiceRequest {
            $updated = $this->transitions->transition(
                $request,
                RequestStatus::Completed,
                $actor,
                'Request completed.',
            );

            $this->enqueueIntegrationEvent->handle(
                $updated->fresh(['client']) ?? $updated,
                WorkflowEventType::ProjectCompleted,
            );

            return $updated;
        });

        $this->syncClickUp->handle($updated, ClickUpSyncEvent::Completed, $employee);
        $this->clearDriveDeliveryKeyboards->handle($updated->fresh('client') ?? $updated);

        return $updated;
    }
}
