<?php

namespace App\Actions;

use App\Enums\ClickUpSyncEvent;
use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Services\RequestStatusTransitionService;

class OpenRequestForClientReview
{
    public function __construct(
        private RequestStatusTransitionService $transitions,
        private DispatchStatusWorkflow $dispatchStatusWorkflow,
    ) {}

    public function handle(ServiceRequest $request, string $note = 'Drive files sent to the client.'): ServiceRequest
    {
        if (in_array($request->status, [
            RequestStatus::ReadyForReview,
            RequestStatus::Completed,
            RequestStatus::Cancelled,
        ], true)) {
            return $request;
        }

        if ($request->status === RequestStatus::PaymentConfirmed) {
            $request = $this->transitions->transition(
                $request,
                RequestStatus::InProgress,
                'drive',
                'Work files uploaded to Drive.',
            );
        }

        if (! in_array($request->status, [RequestStatus::InProgress, RequestStatus::RevisionRequested], true)) {
            return $request;
        }

        $updated = $this->transitions->transition(
            $request,
            RequestStatus::ReadyForReview,
            'drive',
            $note,
        );

        $this->dispatchStatusWorkflow->handle($updated, RequestStatus::ReadyForReview);
        app(SyncClickUpFromStaff::class)->handle($updated, ClickUpSyncEvent::Delivery, null, $note);

        return $updated;
    }
}
