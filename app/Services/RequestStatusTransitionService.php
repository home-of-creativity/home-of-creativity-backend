<?php

namespace App\Services;

use App\Enums\RequestStatus;
use App\Models\RequestStatusHistory;
use App\Models\ServiceRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestStatusTransitionService
{
    public function __construct(private OdooLeadLog $leadLog) {}

    public function transition(
        ServiceRequest $request,
        RequestStatus $to,
        ?string $actor = null,
        ?string $note = null,
    ): ServiceRequest {
        if ($request->status === $to) {
            return $request;
        }

        if (! $request->status->canTransitionTo($to)) {
            throw ValidationException::withMessages([
                'status' => "Cannot transition from {$request->status->value} to {$to->value}.",
            ]);
        }

        return DB::transaction(function () use ($request, $to, $actor, $note): ServiceRequest {
            $from = $request->status;
            $request->forceFill([
                'status' => $to,
                'aggregate_version' => $request->aggregate_version + 1,
            ])->save();

            $history = RequestStatusHistory::query()->create([
                'request_id' => $request->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor' => $actor,
                'note' => $note,
            ]);

            $this->leadLog->statusChanged($request, $to, $actor, $note, (int) $history->id);

            return $request->fresh() ?? $request;
        });
    }
}
