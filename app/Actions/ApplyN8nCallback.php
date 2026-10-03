<?php

namespace App\Actions;

use App\Enums\ClickUpTaskType;
use App\Enums\WorkflowEventType;
use App\Models\ServiceRequest;
use Illuminate\Validation\ValidationException;

class ApplyN8nCallback
{
    public function __construct(private ApplyClickUpMapping $applyClickUpMapping) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(ServiceRequest $request, string $event, array $payload = []): ServiceRequest
    {
        $type = WorkflowEventType::tryFrom($event);
        if (! $type) {
            throw ValidationException::withMessages([
                'event' => 'Unknown workflow event.',
            ]);
        }

        return match ($type) {
            WorkflowEventType::RequestSubmitted,
            WorkflowEventType::PaymentConfirmed,
            WorkflowEventType::RevisionRequested,
            WorkflowEventType::ProjectCompleted,
            WorkflowEventType::DeliveryReady => $request->fresh(['client', 'briefs', 'clickupTasks']) ?? $request,
            WorkflowEventType::TasksReady => $this->alreadyProvisioned($request, $payload)
                ? ($request->fresh(['client', 'briefs', 'clickupTasks']) ?? $request)
                : $this->applyClickUpMapping->handle($request, $payload),
            WorkflowEventType::QuotationReady => throw ValidationException::withMessages([
                'event' => 'Automatic quotations are disabled; send quotation from the dashboard.',
            ]),
        };
    }

    /**
     * A TASKS_READY echo must not replace a task Laravel already created for that department.
     *
     * @param  array<string, mixed>  $payload
     */
    private function alreadyProvisioned(ServiceRequest $request, array $payload): bool
    {
        $type = ClickUpTaskType::fromDepartment((string) ($payload['task_type'] ?? ''));
        if (! $type) {
            return false;
        }

        return $request->clickupTasks()
            ->where('task_type', $type->value)
            ->whereNotNull('clickup_task_id')
            ->where('clickup_task_id', '!=', '')
            ->exists();
    }
}
