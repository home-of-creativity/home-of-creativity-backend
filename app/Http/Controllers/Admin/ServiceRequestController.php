<?php

namespace App\Http\Controllers\Admin;

use App\Actions\CompleteRequest;
use App\Actions\ConfirmRequestPayment;
use App\Actions\DispatchStatusWorkflow;
use App\Actions\EnqueueIntegrationEvent;
use App\Actions\SendQuotation;
use App\Enums\GeminiStatus;
use App\Enums\PaymentMethod;
use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminServiceRequestIndexRequest;
use App\Http\Requests\ConfirmPaymentRequest;
use App\Http\Requests\SendQuotationRequest;
use App\Http\Requests\UpdateServiceRequestStatusRequest;
use App\Http\Resources\ServiceRequestResource;
use App\Jobs\ClassifyWithGeminiJob;
use App\Models\IntegrationEvent;
use App\Models\RequestFile;
use App\Models\ServiceRequest;
use App\Services\RequestStatusTransitionService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ServiceRequestController extends Controller
{
    public function index(AdminServiceRequestIndexRequest $request)
    {
        $query = ServiceRequest::query()->with('client')->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return ServiceRequestResource::collection($query->paginate($request->perPage()))
            ->additional(['message' => 'ok']);
    }

    public function show(ServiceRequest $serviceRequest): ServiceRequestResource
    {
        $serviceRequest->load([
            'client',
            'briefs',
            'events',
            'files',
            'revisions',
            'quotations',
            'quotationDecisions.quotation',
            'invoices',
            'clickupTasks.employee',
            'statusHistory',
            'integrationEvents',
        ]);

        return ServiceRequestResource::make($serviceRequest)
            ->additional(['message' => 'ok']);
    }

    public function update(
        UpdateServiceRequestStatusRequest $request,
        ServiceRequest $serviceRequest,
        RequestStatusTransitionService $transitions,
        CompleteRequest $completeRequest,
        DispatchStatusWorkflow $dispatchStatusWorkflow,
    ): ServiceRequestResource {
        $status = RequestStatus::from($request->validated('status'));

        if ($status === RequestStatus::Completed) {
            $serviceRequest = $completeRequest->handle($serviceRequest, 'admin');
        } else {
            $serviceRequest = $transitions->transition($serviceRequest, $status, 'admin');
            $dispatchStatusWorkflow->handle($serviceRequest, $status);
        }

        $serviceRequest->load('client');

        return ServiceRequestResource::make($serviceRequest)
            ->additional(['message' => 'Updated.']);
    }

    public function sendQuotation(
        SendQuotationRequest $request,
        ServiceRequest $serviceRequest,
        SendQuotation $sendQuotation,
    ): ServiceRequestResource {
        $lines = $request->validated('lines');
        $sendQuotation->handle(
            $serviceRequest,
            $lines
                ? (float) collect($lines)->sum(fn (array $line): float => (float) $line['amount'] * (float) ($line['units'] ?? 1))
                : (float) $request->validated('amount'),
            $request->validated('notes'),
            'admin',
            null,
            $lines,
        );

        return ServiceRequestResource::make($serviceRequest->fresh([
            'client', 'quotations', 'quotationDecisions',
        ]))->additional(['message' => 'Quotation sent.']);
    }

    public function confirmPayment(
        ConfirmPaymentRequest $request,
        ServiceRequest $serviceRequest,
        ConfirmRequestPayment $confirmRequestPayment,
    ): ServiceRequestResource {
        $confirmRequestPayment->handle(
            $serviceRequest,
            PaymentMethod::from($request->validated('payment_method')),
        );

        return ServiceRequestResource::make($serviceRequest->fresh([
            'client', 'files', 'invoices',
        ]))->additional(['message' => 'Payment confirmation queued for Gemini.']);
    }

    public function retryGemini(ServiceRequest $serviceRequest): ServiceRequestResource
    {
        abort_unless($serviceRequest->gemini_status === GeminiStatus::Failed, 422, 'Gemini retry is only available after failure.');

        $serviceRequest->forceFill([
            'gemini_status' => GeminiStatus::Pending,
            'gemini_error' => null,
        ])->save();

        ClassifyWithGeminiJob::dispatch($serviceRequest->id);

        return ServiceRequestResource::make($serviceRequest->fresh(['client', 'briefs']))
            ->additional(['message' => 'Gemini retry queued.']);
    }

    public function retryIntegrationEvent(IntegrationEvent $integrationEvent, EnqueueIntegrationEvent $enqueue): ServiceRequestResource
    {
        $request = ServiceRequest::query()->where('uuid', $integrationEvent->request_uuid)->firstOrFail();
        $enqueue->retry($integrationEvent);

        return ServiceRequestResource::make($request->fresh(['integrationEvents']))
            ->additional(['message' => 'Integration event retry queued.']);
    }

    public function receipt(ServiceRequest $serviceRequest, RequestFile $file): StreamedResponse
    {
        abort_unless($file->request_id === $serviceRequest->id, 404);
        abort_unless(in_array($file->kind, ['payment_receipt', 'brief_attachment'], true), 404);
        abort_unless($file->path && Storage::disk('local')->exists($file->path), 404);

        return Storage::disk('local')->response($file->path, $file->original_name);
    }
}
