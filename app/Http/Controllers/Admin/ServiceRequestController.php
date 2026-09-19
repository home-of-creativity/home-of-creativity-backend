<?php

namespace App\Http\Controllers\Admin;

use App\Actions\CompleteRequest;
use App\Actions\ConfirmRequestPayment;
use App\Actions\DispatchStatusWorkflow;
use App\Actions\EnqueueIntegrationEvent;
use App\Actions\EnsureRequestDriveFolder;
use App\Actions\HydrateServiceRequestFromOdoo;
use App\Actions\RenewSubscription;
use App\Actions\ReRequestReceipt;
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
use App\Models\OpsSetting;
use App\Models\RequestFile;
use App\Models\ServiceRequest;
use App\Services\RequestStatusTransitionService;
use App\Support\ShamCashQr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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

    public function show(
        ServiceRequest $serviceRequest,
        HydrateServiceRequestFromOdoo $hydrateServiceRequestFromOdoo,
        EnsureRequestDriveFolder $ensureRequestDriveFolder,
    ): ServiceRequestResource {
        $serviceRequest = $hydrateServiceRequestFromOdoo->handle($serviceRequest);

        if (blank($serviceRequest->google_drive_folder_id)
            && ($serviceRequest->paid_at
                || $serviceRequest->status === RequestStatus::PaymentConfirmed
                || (float) $serviceRequest->amount_paid > 0)) {
            try {
                $serviceRequest = $ensureRequestDriveFolder->handle($serviceRequest);
            } catch (\Throwable $exception) {
                Log::warning('Drive folder on request show failed.', [
                    'request' => $serviceRequest->number,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

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
            'pricingPackage.subcategory.category',
            'subscriptions',
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
        $requiresFullPayment = $request->has('requires_full_payment')
            ? (bool) $request->validated('requires_full_payment')
            : null;
        $sendQuotation->handle(
            $serviceRequest,
            $lines
                ? (float) collect($lines)->sum(fn (array $line): float => (float) $line['amount'] * (float) ($line['units'] ?? 1))
                : (float) $request->validated('amount'),
            $request->validated('notes'),
            'admin',
            null,
            $lines,
            false,
            $requiresFullPayment,
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
            (float) $request->validated('amount'),
        );

        return ServiceRequestResource::make($serviceRequest->fresh([
            'client', 'files', 'invoices',
        ]))->additional(['message' => 'Payment confirmed.']);
    }

    public function retryGemini(ServiceRequest $serviceRequest): ServiceRequestResource
    {
        abort_unless($serviceRequest->gemini_status === GeminiStatus::Failed, 422, 'Gemini retry is only available after failure.');

        $serviceRequest->forceFill([
            'gemini_status' => GeminiStatus::Pending,
            'gemini_error' => null,
        ])->save();

        try {
            ClassifyWithGeminiJob::dispatch($serviceRequest->id);
        } catch (\Throwable $exception) {
            $serviceRequest->forceFill([
                'gemini_status' => GeminiStatus::Failed,
                'gemini_error' => $exception->getMessage(),
                'gemini_processed_at' => now(),
            ])->save();
        }

        return ServiceRequestResource::make($serviceRequest->fresh(['client', 'briefs']))
            ->additional(['message' => 'Gemini retry queued.']);
    }

    public function reRequestReceipt(Request $request, ServiceRequest $serviceRequest, ReRequestReceipt $reRequestReceipt): ServiceRequestResource
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $reRequestReceipt->handle(
            $serviceRequest,
            $validated['reason'] ?? 'الوصل غير واضح',
        );

        return ServiceRequestResource::make($updated)
            ->additional(['message' => 'Receipt re-requested.']);
    }

    public function renew(ServiceRequest $serviceRequest, RenewSubscription $renewSubscription): ServiceRequestResource
    {
        $updated = $renewSubscription->handle($serviceRequest);

        return ServiceRequestResource::make($updated)
            ->additional(['message' => 'Renewal started.']);
    }

    public function opsSettings(): JsonResponse
    {
        return response()->json([
            'data' => $this->opsSettingsPayload(),
            'message' => 'ok',
        ]);
    }

    public function shamCashQrPreview(): BinaryFileResponse
    {
        $absolute = ShamCashQr::absolutePath();
        abort_unless(is_string($absolute), 404, 'Sham Cash QR is not configured.');

        return response()->file($absolute, [
            'Content-Type' => mime_content_type($absolute) ?: 'image/png',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function uploadShamCashQr(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $stored = $request->file('file')?->store('payment', 'local');
        abort_unless(is_string($stored) && $stored !== '', 422, 'Failed to store QR image.');

        $previous = OpsSetting::getValue('sham_cash_qr_path');
        if (filled($previous) && $previous !== $stored && Storage::disk('local')->exists($previous)) {
            Storage::disk('local')->delete($previous);
        }

        OpsSetting::setValue('sham_cash_qr_path', $stored);
        OpsSetting::setValue('sham_cash_qr_updated_at', now()->toIso8601String());

        return response()->json([
            'data' => $this->opsSettingsPayload(),
            'message' => 'QR saved.',
        ]);
    }

    public function socialProfile(): JsonResponse
    {
        return response()->json([
            'data' => $this->socialProfilePayload(),
            'message' => 'ok',
        ]);
    }

    public function updateSocialProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'display_name' => ['nullable', 'string', 'max:80'],
            'bio' => ['nullable', 'string', 'max:280'],
            'theme' => ['required', 'in:cream,purple,dark'],
        ]);

        OpsSetting::setValue('social_linktree_profile', json_encode($validated, JSON_UNESCAPED_UNICODE));

        return response()->json([
            'data' => $this->socialProfilePayload(),
            'message' => 'ok',
        ]);
    }

    /**
     * @return array{sham_cash_qr: bool, sham_cash_qr_updated_at: string|null}
     */
    private function opsSettingsPayload(): array
    {
        return [
            'sham_cash_qr' => ShamCashQr::relativePath() !== null,
            'sham_cash_qr_updated_at' => OpsSetting::getValue('sham_cash_qr_updated_at'),
        ];
    }

    /**
     * @return array{display_name: string, bio: string, theme: string}
     */
    private function socialProfilePayload(): array
    {
        $raw = OpsSetting::getValue('social_linktree_profile');
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $theme = is_array($data) && in_array($data['theme'] ?? null, ['cream', 'purple', 'dark'], true)
            ? $data['theme']
            : 'cream';

        return [
            'display_name' => is_array($data) ? (string) ($data['display_name'] ?? '') : '',
            'bio' => is_array($data) ? (string) ($data['bio'] ?? '') : '',
            'theme' => $theme,
        ];
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
