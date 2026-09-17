<?php

namespace App\Jobs;

use App\Actions\EnqueueIntegrationEvent;
use App\Actions\IssueInvoice;
use App\Actions\ProvisionClickUpTasks;
use App\Enums\GeminiStatus;
use App\Enums\IntegrationEventStatus;
use App\Enums\RequestStatus;
use App\Enums\WorkflowEventType;
use App\Models\ServiceRequest;
use App\Services\GeminiService;
use App\Services\RequestStatusTransitionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ClassifyWithGeminiJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $requestId) {}

    public function handle(
        GeminiService $gemini,
        RequestStatusTransitionService $transitions,
        EnqueueIntegrationEvent $enqueueIntegrationEvent,
        IssueInvoice $issueInvoice,
        ProvisionClickUpTasks $provisionClickUpTasks,
    ): void {
        unset($issueInvoice);
        $request = ServiceRequest::query()->with('briefs')->find($this->requestId);
        if (! $request || $request->gemini_status === GeminiStatus::Success) {
            return;
        }

        $request->forceFill([
            'gemini_status' => GeminiStatus::Processing,
            'gemini_attempts' => $request->gemini_attempts + 1,
        ])->save();

        try {
            $result = $gemini->classify($request->title, $request->description);
        } catch (Throwable $exception) {
            $request->forceFill([
                'gemini_status' => GeminiStatus::Failed,
                'gemini_error' => $exception->getMessage(),
                'gemini_processed_at' => now(),
            ])->save();

            Log::warning('Gemini job failed.', [
                'request' => $request->number,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        DB::transaction(function () use ($request, $result, $gemini, $transitions, $enqueueIntegrationEvent, $provisionClickUpTasks): void {
            $request->refresh();
            $gemini->persistBriefs($request, $result['briefs']);

            $request->forceFill([
                'work_type' => $result['work_type'],
                'gemini_status' => GeminiStatus::Success,
                'gemini_error' => null,
                'gemini_processed_at' => now(),
            ])->save();

            if ($request->status === RequestStatus::AwaitingPayment) {
                $transitions->transition($request, RequestStatus::PaymentConfirmed, 'system', 'Gemini classification succeeded.');
            }

            $fresh = $request->fresh(['briefs', 'client', 'clickupTasks', 'integrationEvents']) ?? $request;

            $alreadyDispatched = $fresh->integrationEvents
                ->where('event_type', WorkflowEventType::PaymentConfirmed)
                ->where('status', IntegrationEventStatus::Dispatched)
                ->isNotEmpty();

            $eventUuid = (string) Str::uuid();
            if (! $alreadyDispatched) {
                $event = $enqueueIntegrationEvent->handle(
                    $fresh,
                    WorkflowEventType::PaymentConfirmed,
                    [
                        'work_type' => $fresh->work_type?->value,
                        'briefs' => $fresh->briefs->map(fn ($brief) => [
                            'id' => $brief->id,
                            'type' => $brief->type ?? $brief->department,
                            'department' => $brief->department,
                            'brief' => $brief->brief,
                        ])->values()->all(),
                    ],
                    $eventUuid,
                );
                $eventUuid = $event->event_uuid;
            } else {
                $eventUuid = (string) ($fresh->integrationEvents
                    ->where('event_type', WorkflowEventType::PaymentConfirmed)
                    ->sortByDesc('id')
                    ->first()
                    ?->event_uuid ?? $eventUuid);
            }

            $provisionClickUpTasks->handle(
                $fresh->fresh(['briefs', 'client', 'clickupTasks']) ?? $fresh,
                $eventUuid,
            );
        });
    }
}
