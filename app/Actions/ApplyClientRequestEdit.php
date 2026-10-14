<?php

namespace App\Actions;

use App\Models\ServiceRequest;
use App\Services\OdooLeadLog;

/**
 * A client's edit of a request's title or description from a bot. The request is saved for the
 * dashboard, the Odoo opportunity gets a note with the new text, and the ClickUp sales task is
 * rewritten.
 */
class ApplyClientRequestEdit
{
    public function __construct(
        private OdooLeadLog $odooLeadLog,
        private ProvisionSalesClickUpTask $salesTask,
    ) {}

    public function handle(ServiceRequest $request, ?string $title, ?string $description): ServiceRequest
    {
        $previousTitle = (string) $request->title;
        $request->forceFill(array_filter([
            'title' => $title !== null ? trim($title) : null,
            'description' => $description !== null ? mb_substr(trim($description), 0, 10000) : null,
        ], fn (?string $value): bool => $value !== null && $value !== ''))->save();

        if (! $request->wasChanged(['title', 'description'])) {
            return $request;
        }

        $this->odooLeadLog->requestEdited($request, $previousTitle);
        $this->salesTask->syncEditedRequest($request);

        return $request->fresh() ?? $request;
    }
}
