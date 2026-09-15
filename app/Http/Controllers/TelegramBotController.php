<?php

namespace App\Http\Controllers;

use App\Actions\ApproveQuotation;
use App\Actions\CompleteRequest;
use App\Actions\NotifyEmployees;
use App\Actions\ProvisionSalesClickUpTask;
use App\Actions\PushClientToOdoo;
use App\Actions\RejectQuotation;
use App\Actions\RequestRevision;
use App\Actions\SubmitServiceRequest;
use App\Actions\SyncClickUpFromStaff;
use App\Enums\ClickUpSyncEvent;
use App\Enums\EmployeeProfession;
use App\Enums\RequestSource;
use App\Enums\RequestStatus;
use App\Http\Requests\TelegramLinkRequest;
use App\Http\Requests\TelegramSubmitRequest;
use App\Http\Resources\ClientResource;
use App\Http\Resources\ServiceRequestResource;
use App\Models\Client;
use App\Models\RequestFile;
use App\Models\ServiceRequest;
use App\Models\SupportMessage;
use App\Services\ClickUpStatusMapper;
use App\Services\RequestStatusTransitionService;
use App\Support\ResolveServiceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class TelegramBotController extends Controller
{
    public function link(TelegramLinkRequest $request, PushClientToOdoo $pushClientToOdoo): JsonResponse
    {
        $client = Client::query()->updateOrCreate(
            ['telegram_user_id' => $request->validated('telegram_user_id')],
            [
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'phone' => $request->validated('phone'),
                'locale' => $request->validated('locale') ?? 'ar',
            ],
        );

        $client = $pushClientToOdoo->handle($client);

        return response()->json([
            'data' => ClientResource::make($client),
            'message' => 'Linked.',
        ]);
    }

    public function submit(TelegramSubmitRequest $request, SubmitServiceRequest $submitServiceRequest): JsonResponse
    {
        $client = Client::query()
            ->where('telegram_user_id', $request->validated('telegram_user_id'))
            ->firstOrFail();

        $serviceRequest = $submitServiceRequest->handle($client, [
            'title' => $request->validated('title'),
            'description' => trim((string) ($request->validated('description') ?? '')) ?: 'انظر المرفقات.',
            'source' => RequestSource::Telegram,
            'attachments' => $request->validated('attachments') ?? [],
        ]);

        return response()->json([
            'data' => ServiceRequestResource::make($serviceRequest),
            'message' => 'Created.',
        ], 201);
    }

    public function index(Request $request, ClickUpStatusMapper $mapper): JsonResponse
    {
        $client = Client::query()
            ->where('telegram_user_id', $request->query('telegram_user_id'))
            ->firstOrFail();

        $items = $client->requests()
            ->latest('id')
            ->get()
            ->map(fn (ServiceRequest $item) => [
                'number' => $item->number,
                'title' => $item->title,
                'status' => $item->status->value,
                'execution_status' => $item->execution_status?->value,
                'execution_status_label' => $mapper->toClientLabel($item->execution_status),
                'can_edit' => $item->status->allowsClientEdit(),
            ]);

        return response()->json(['data' => $items, 'message' => 'ok']);
    }

    public function update(Request $request, ServiceRequest $serviceRequest): ServiceRequestResource
    {
        $client = Client::query()
            ->where('telegram_user_id', $request->input('telegram_user_id'))
            ->firstOrFail();

        abort_unless($serviceRequest->client_id === $client->id, 403);
        abort_unless($serviceRequest->status->allowsClientEdit(), 422, 'Request can no longer be edited.');

        $validated = $request->validate([
            'telegram_user_id' => ['required', 'string'],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'max:10000'],
        ]);

        $serviceRequest->forceFill(array_filter([
            'title' => $validated['title'] ?? null,
            'description' => $validated['description'] ?? null,
        ], fn ($value) => $value !== null))->save();

        return ServiceRequestResource::make($serviceRequest->fresh('client'))
            ->additional(['message' => 'Updated.']);
    }

    public function approve(Request $request, ServiceRequest $serviceRequest, ApproveQuotation $approveQuotation): ServiceRequestResource
    {
        $this->assertClientOwns($request, $serviceRequest);
        $updated = $approveQuotation->handle($serviceRequest);

        return ServiceRequestResource::make($updated->fresh('client'))
            ->additional(['message' => 'Approved.']);
    }

    public function reject(Request $request, ServiceRequest $serviceRequest, RejectQuotation $rejectQuotation): ServiceRequestResource
    {
        $this->assertClientOwns($request, $serviceRequest);
        $validated = $request->validate([
            'telegram_user_id' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:5000'],
        ]);

        $updated = $rejectQuotation->handle($serviceRequest, $validated['reason']);

        return ServiceRequestResource::make($updated->fresh('client'))
            ->additional(['message' => 'Rejected.']);
    }

    public function receipt(
        Request $request,
        ServiceRequest $serviceRequest,
        NotifyEmployees $notifyEmployees,
        ProvisionSalesClickUpTask $provisionSalesClickUpTask,
    ): JsonResponse {
        $this->assertClientOwns($request, $serviceRequest);
        abort_unless($serviceRequest->status === RequestStatus::AwaitingPayment, 422, 'Receipt upload is only allowed while awaiting payment.');

        $validated = $request->validate([
            'telegram_user_id' => ['required', 'string'],
            'file_name' => ['required', 'string', 'max:255'],
            'file_base64' => ['required', 'string'],
            'mime_type' => ['nullable', 'string', 'max:100'],
        ]);

        $binary = base64_decode($validated['file_base64'], true);
        if ($binary === false) {
            throw ValidationException::withMessages(['file_base64' => 'Invalid file payload.']);
        }

        if (strlen($binary) > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['file_base64' => 'File exceeds 5MB limit.']);
        }

        $mime = $validated['mime_type'] ?? 'application/octet-stream';
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
        if (! in_array($mime, $allowed, true)) {
            throw ValidationException::withMessages(['mime_type' => 'Unsupported file type.']);
        }

        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin',
        };

        $path = "receipts/{$serviceRequest->number}-".now()->format('YmdHis').".{$extension}";
        Storage::disk('local')->put($path, $binary);

        $receiptFile = RequestFile::query()->create([
            'request_id' => $serviceRequest->id,
            'kind' => 'payment_receipt',
            'original_name' => $validated['file_name'],
            'path' => $path,
        ]);

        $serviceRequest->load('client');
        $displayNumber = ResolveServiceRequest::displayNumber($serviceRequest);
        $caption = "📎 رفع الزبون وصل دفع\n#{$displayNumber} — {$serviceRequest->title}\n{$serviceRequest->client?->name}";

        $notifyEmployees->handle(
            $serviceRequest,
            EmployeeProfession::Sales,
            $caption,
            null,
            [
                'path' => Storage::disk('local')->path($path),
                'mime' => $mime,
                'name' => $validated['file_name'],
            ],
        );

        $provisionSalesClickUpTask->appendReceipt($serviceRequest, $receiptFile);

        return response()->json([
            'data' => ['stored' => true],
            'message' => 'Receipt uploaded.',
        ]);
    }

    public function revision(Request $request, ServiceRequest $serviceRequest, RequestRevision $requestRevision): ServiceRequestResource
    {
        $this->assertClientOwns($request, $serviceRequest);
        abort_unless($serviceRequest->status === RequestStatus::ReadyForReview, 422, 'Revision is only available after delivery.');

        $validated = $request->validate([
            'telegram_user_id' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:5000'],
        ]);

        $updated = $requestRevision->handle($serviceRequest, $validated['reason']);

        return ServiceRequestResource::make($updated->fresh('client'))
            ->additional(['message' => 'Revision requested.']);
    }

    public function acknowledge(Request $request, ServiceRequest $serviceRequest, NotifyEmployees $notifyEmployees): JsonResponse
    {
        $this->assertClientOwns($request, $serviceRequest);

        $serviceRequest->load('client');
        $displayNumber = ResolveServiceRequest::displayNumber($serviceRequest);
        $notifyEmployees->handle(
            $serviceRequest,
            EmployeeProfession::Sales,
            "✅ أكد الزبون اهتمامه بالطلب\n#{$displayNumber} — {$serviceRequest->title}\n{$serviceRequest->client?->name}",
        );

        return response()->json([
            'data' => ['acknowledged' => true],
            'message' => 'Acknowledged.',
        ]);
    }

    public function cancel(Request $request, ServiceRequest $serviceRequest, RequestStatusTransitionService $transitions, NotifyEmployees $notifyEmployees): ServiceRequestResource
    {
        $this->assertClientOwns($request, $serviceRequest);

        if (! $serviceRequest->status->canTransitionTo(RequestStatus::Cancelled)) {
            throw ValidationException::withMessages([
                'status' => 'This request cannot be cancelled in its current state.',
            ]);
        }

        $updated = $transitions->transition($serviceRequest, RequestStatus::Cancelled, 'client', 'Client cancelled via Telegram.');
        $fresh = $updated->fresh('client') ?? $updated;
        app(SyncClickUpFromStaff::class)->handle($fresh, ClickUpSyncEvent::Cancelled);
        $displayNumber = ResolveServiceRequest::displayNumber($fresh);
        $notifyEmployees->handle(
            $fresh,
            EmployeeProfession::Sales,
            "❌ ألغى الزبون الطلب\n#{$displayNumber} — {$fresh->title}\n{$fresh->client?->name}",
        );

        return ServiceRequestResource::make($fresh)
            ->additional(['message' => 'Cancelled.']);
    }

    public function complete(Request $request, ServiceRequest $serviceRequest, CompleteRequest $completeRequest): ServiceRequestResource
    {
        $this->assertClientOwns($request, $serviceRequest);
        abort_unless($serviceRequest->status === RequestStatus::ReadyForReview, 422, 'Only ready-for-review requests can be completed.');

        $updated = $completeRequest->handle($serviceRequest, 'client');

        return ServiceRequestResource::make($updated->fresh('client'))
            ->additional(['message' => 'Completed.']);
    }

    public function support(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'telegram_user_id' => ['required', 'string'],
            'message' => ['required', 'string', 'max:5000'],
            'request_number' => ['nullable', 'string'],
        ]);

        $client = Client::query()
            ->where('telegram_user_id', $validated['telegram_user_id'])
            ->firstOrFail();

        $requestId = null;
        if (filled($validated['request_number'] ?? null)) {
            $requestId = ServiceRequest::query()
                ->where('number', $validated['request_number'])
                ->where('client_id', $client->id)
                ->value('id');
        }

        SupportMessage::query()->create([
            'client_id' => $client->id,
            'request_id' => $requestId,
            'message' => $validated['message'],
        ]);

        return response()->json(['data' => ['stored' => true], 'message' => 'Support message saved.']);
    }

    private function assertClientOwns(Request $request, ServiceRequest $serviceRequest): void
    {
        $client = Client::query()
            ->where('telegram_user_id', $request->input('telegram_user_id'))
            ->firstOrFail();

        abort_unless($serviceRequest->client_id === $client->id, 403);
    }
}
