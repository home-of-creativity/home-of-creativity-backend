<?php

namespace App\Http\Controllers;

use App\Actions\CompleteRequest;
use App\Actions\DispatchStatusWorkflow;
use App\Actions\RecordDelivery;
use App\Actions\RequestStaffJoin;
use App\Actions\SendQuotation;
use App\Actions\SyncClickUpFromStaff;
use App\Enums\ClickUpSyncEvent;
use App\Enums\RequestStatus;
use App\Http\Requests\StaffJoinRequest;
use App\Http\Requests\StaffReplyRequest;
use App\Http\Requests\StaffSendQuotationRequest;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\ServiceRequestResource;
use App\Models\Employee;
use App\Models\ServiceRequest;
use App\Services\RequestStatusTransitionService;
use App\Services\TelegramNotifier;
use App\Support\ResolveServiceRequest;
use App\Support\StatusLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class StaffBotController extends Controller
{
    public function me(Request $request): JsonResponse|EmployeeResource
    {
        $telegramId = (string) $request->query('telegram_user_id', '');
        $employee = Employee::query()
            ->where('telegram_user_id', $telegramId)
            ->first();

        if (! $employee) {
            return response()->json([
                'data' => null,
                'message' => 'Employee is not registered.',
            ], 404);
        }

        return EmployeeResource::make($employee)
            ->additional(['message' => 'ok']);
    }

    public function join(StaffJoinRequest $request, RequestStaffJoin $join): JsonResponse
    {
        $data = $request->validated();
        $employee = $join->handle(
            $data['telegram_user_id'],
            $data['name'],
            $data['telegram_username'] ?? null,
        );

        $wasRecentlyCreated = $employee->wasRecentlyCreated;

        return EmployeeResource::make($employee)
            ->additional(['message' => 'ok'])
            ->response()
            ->setStatusCode($wasRecentlyCreated ? 201 : 200);
    }

    public function reply(
        StaffReplyRequest $request,
        TelegramNotifier $telegram,
        ResolveServiceRequest $resolveServiceRequest,
    ): JsonResponse {
        $employee = Employee::query()
            ->approved()
            ->where('telegram_user_id', $request->validated('telegram_user_id'))
            ->firstOrFail();

        abort_unless($employee->isSales(), 403, 'Only sales staff can reply to clients.');

        $serviceRequest = $resolveServiceRequest
            ->byReference($request->validated('request_number'))
            ->load('client');

        $chatId = $serviceRequest->client?->telegram_user_id;
        if (! filled($chatId)) {
            throw ValidationException::withMessages([
                'request_number' => 'This client has no Telegram conversation.',
            ]);
        }

        if (! $telegram->configured('client')) {
            throw ValidationException::withMessages([
                'text' => 'The client Telegram bot is not configured.',
            ]);
        }

        $displayNumber = ResolveServiceRequest::displayNumber($serviceRequest);
        $message = "رسالة من {$employee->name} بخصوص الطلب #{$displayNumber}:\n\n".$request->validated('text');

        $clientButtons = $this->clientActionButtons($serviceRequest);
        if ($clientButtons !== null) {
            $telegram->sendInlineActions((string) $chatId, $message, $clientButtons, 'client');
        } else {
            $telegram->send((string) $chatId, $message, 'client');
        }

        app(SyncClickUpFromStaff::class)->handle(
            $serviceRequest,
            ClickUpSyncEvent::Contacted,
            $employee,
            $request->validated('text'),
        );

        return response()->json([
            'data' => [
                'sent' => true,
                'request_number' => $serviceRequest->number,
            ],
            'message' => 'Forwarded.',
        ]);
    }

    public function replyableRequests(Request $request): JsonResponse
    {
        $employee = $this->approvedEmployee($request);
        abort_unless($employee->isSales(), 403, 'Only sales staff can list client conversations.');

        $items = ServiceRequest::query()
            ->with(['client', 'pricingPackage'])
            ->whereIn('status', [
                RequestStatus::Submitted,
                RequestStatus::QuotationSent,
                RequestStatus::QuotationRejected,
                RequestStatus::AwaitingPayment,
            ])
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (ServiceRequest $item) => $this->staffCard($item));

        return response()->json(['data' => $items, 'message' => 'ok']);
    }

    public function quotableRequests(Request $request): JsonResponse
    {
        $employee = $this->approvedEmployee($request);
        abort_unless($employee->isSales(), 403, 'Only sales staff can send quotations.');

        $items = ServiceRequest::query()
            ->with(['client', 'pricingPackage'])
            ->whereIn('status', [RequestStatus::Submitted, RequestStatus::QuotationRejected])
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (ServiceRequest $item) => $this->staffCard($item));

        return response()->json(['data' => $items, 'message' => 'ok']);
    }

    public function sendQuotation(
        StaffSendQuotationRequest $request,
        SendQuotation $sendQuotation,
        ResolveServiceRequest $resolveServiceRequest,
    ): JsonResponse {
        $employee = Employee::query()
            ->approved()
            ->where('telegram_user_id', $request->validated('telegram_user_id'))
            ->firstOrFail();

        abort_unless($employee->isSales(), 403, 'Only sales staff can send quotations.');

        $serviceRequest = $resolveServiceRequest
            ->byReference($request->validated('request_number'))
            ->load('client');

        $quotation = $sendQuotation->handle(
            $serviceRequest,
            (float) $request->validated('amount'),
            $request->validated('notes'),
            'staff:'.$employee->code,
            $employee,
        );

        return response()->json([
            'data' => [
                'sent' => true,
                'request_number' => $serviceRequest->number,
                'quotation_version' => $quotation->version,
                'amount' => $quotation->amount,
            ],
            'message' => 'Quotation sent.',
        ]);
    }

    public function tasks(Request $request): JsonResponse
    {
        $employee = $this->approvedEmployee($request);

        $tasks = ServiceRequest::query()
            ->with(['client', 'clickupTasks', 'revisions', 'pricingPackage'])
            ->whereIn('status', [
                RequestStatus::InProgress,
                RequestStatus::RevisionRequested,
                RequestStatus::ReadyForReview,
            ])
            ->whereHas('clickupTasks', fn ($query) => $query->where('employee_id', $employee->id))
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (ServiceRequest $item) => array_merge($this->staffCard($item), [
                'clickup_url' => $item->clickupTasks->firstWhere('employee_id', $employee->id)?->clickup_url,
                'revision_comments' => $item->status === RequestStatus::RevisionRequested
                    ? $item->revisions->sortByDesc('id')->first()?->comments
                    : null,
            ]));

        return response()->json(['data' => $tasks, 'message' => 'ok']);
    }

    public function newRequests(Request $request): JsonResponse
    {
        $employee = $this->approvedEmployee($request);
        abort_unless($employee->isSales(), 403, 'Only sales staff can list new intake requests.');

        $items = ServiceRequest::query()
            ->with(['client', 'clickupTasks', 'pricingPackage'])
            ->where('status', RequestStatus::Submitted)
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (ServiceRequest $item) => array_merge($this->staffCard($item), [
                'description' => $item->description,
                'sales_clickup_url' => $item->clickupTasks->firstWhere('task_type', 'sales')?->clickup_url,
            ]));

        return response()->json(['data' => $items, 'message' => 'ok']);
    }

    public function deliver(
        Request $request,
        RecordDelivery $recordDelivery,
        TelegramNotifier $telegram,
        ResolveServiceRequest $resolveServiceRequest,
    ): JsonResponse {
        $employee = $this->approvedEmployee($request);
        $validated = $request->validate([
            'telegram_user_id' => ['required', 'string'],
            'request_number' => ['required', 'string'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'file_name' => ['nullable', 'string', 'max:255'],
            'file_base64' => ['nullable', 'string'],
        ]);

        $serviceRequest = $resolveServiceRequest
            ->byReference($validated['request_number'])
            ->load('client');

        abort_unless($employee->isAssignedTo($serviceRequest), 403, 'This task is not assigned to you.');
        abort_unless(in_array($serviceRequest->status, [
            RequestStatus::InProgress,
            RequestStatus::RevisionRequested,
        ], true), 422, 'Only in-progress or revision tasks can be delivered.');

        $filePath = null;
        if (filled($validated['file_base64'] ?? null)) {
            $binary = base64_decode((string) $validated['file_base64'], true);
            if ($binary === false) {
                throw ValidationException::withMessages(['file_base64' => 'Invalid file payload.']);
            }
            $filePath = "deliveries/{$serviceRequest->number}-".now()->format('YmdHis').'.bin';
            Storage::disk('local')->put($filePath, $binary);
        }

        $updated = $recordDelivery->handle(
            $serviceRequest,
            $employee,
            $validated['notes'] ?? null,
            $filePath,
        );

        app(SyncClickUpFromStaff::class)->handle(
            $updated,
            ClickUpSyncEvent::Delivery,
            $employee,
            $validated['notes'] ?? null,
        );

        $chatId = $updated->client?->telegram_user_id;
        if ($chatId && $telegram->configured('client')) {
            $telegram->send(
                (string) $chatId,
                'تم تسليم العمل للطلب #'.ResolveServiceRequest::displayNumber($updated).".\n".($validated['notes'] ?? ''),
                'client',
            );
        }

        return response()->json([
            'data' => ServiceRequestResource::make($updated),
            'message' => 'Delivery recorded.',
        ]);
    }

    public function complete(
        Request $request,
        CompleteRequest $completeRequest,
        ResolveServiceRequest $resolveServiceRequest,
    ): JsonResponse {
        $employee = $this->approvedEmployee($request);
        abort_unless($employee->isSales(), 403, 'Only sales staff can mark requests complete.');
        $validated = $request->validate([
            'telegram_user_id' => ['required', 'string'],
            'request_number' => ['required', 'string'],
        ]);

        $serviceRequest = $resolveServiceRequest->byReference($validated['request_number']);

        abort_unless($serviceRequest->status === RequestStatus::ReadyForReview, 422, 'Only ready-for-review requests can be completed.');

        $updated = $completeRequest->handle($serviceRequest, 'staff', $employee);

        return response()->json([
            'data' => ServiceRequestResource::make($updated),
            'message' => 'Completed.',
        ]);
    }

    public function markInProgress(
        Request $request,
        ResolveServiceRequest $resolveServiceRequest,
        RequestStatusTransitionService $transitions,
        DispatchStatusWorkflow $dispatchStatusWorkflow,
    ): JsonResponse {
        $employee = $this->approvedEmployee($request);
        $validated = $request->validate([
            'telegram_user_id' => ['required', 'string'],
            'request_number' => ['required', 'string'],
        ]);

        $serviceRequest = $resolveServiceRequest->byReference($validated['request_number']);
        abort_unless(in_array($serviceRequest->status, [
            RequestStatus::PaymentConfirmed,
            RequestStatus::InProgress,
        ], true), 422, 'Only paid requests can move to in progress.');

        if ($serviceRequest->status !== RequestStatus::InProgress) {
            $serviceRequest = $transitions->transition($serviceRequest, RequestStatus::InProgress, 'staff:'.$employee->code);
            $dispatchStatusWorkflow->handle($serviceRequest, RequestStatus::InProgress);
        }

        return response()->json([
            'data' => ServiceRequestResource::make($serviceRequest->fresh(['client'])),
            'message' => 'In progress.',
        ]);
    }

    public function progressableRequests(Request $request): JsonResponse
    {
        $employee = $this->approvedEmployee($request);

        $items = ServiceRequest::query()
            ->with(['client', 'pricingPackage', 'clickupTasks.employee'])
            ->where('status', RequestStatus::PaymentConfirmed)
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (ServiceRequest $item) => $this->staffCard($item));

        return response()->json(['data' => $items, 'message' => 'ok']);
    }

    public function completableRequests(Request $request): JsonResponse
    {
        $employee = $this->approvedEmployee($request);
        abort_unless($employee->isSales(), 403, 'Only sales staff can complete requests.');

        $items = ServiceRequest::query()
            ->with(['client', 'pricingPackage', 'clickupTasks.employee'])
            ->where('status', RequestStatus::ReadyForReview)
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (ServiceRequest $item) => $this->staffCard($item));

        return response()->json(['data' => $items, 'message' => 'ok']);
    }

    /**
     * @return array<string, mixed>
     */
    private function staffCard(ServiceRequest $item): array
    {
        $assignee = $item->relationLoaded('clickupTasks')
            ? $item->clickupTasks->map(fn ($task) => $task->employee?->name)->filter()->unique()->implode('، ')
            : '';

        return [
            'number' => $item->number,
            'display_number' => ResolveServiceRequest::displayNumber($item),
            'title' => $item->title,
            'status' => $item->status->value,
            'status_label' => StatusLabel::requestAr($item->status->value),
            'client_name' => $item->client?->name,
            'telegram_url' => $item->client?->telegramPrivateUrl(),
            'company_name' => $item->client?->company_name,
            'package_name' => $item->pricingPackage?->name_ar ?: $item->pricingPackage?->name_en,
            'is_manual' => $item->pricing_package_id === null,
            'assignee' => $assignee !== '' ? $assignee : null,
        ];
    }

    private function approvedEmployee(Request $request): Employee
    {
        $telegramId = (string) $request->input('telegram_user_id', $request->query('telegram_user_id', ''));

        return Employee::query()
            ->approved()
            ->where('telegram_user_id', $telegramId)
            ->firstOrFail();
    }

    /**
     * @return list<array{text: string, callback_data: string}>|null
     */
    private function clientActionButtons(ServiceRequest $serviceRequest): ?array
    {
        $ref = ResolveServiceRequest::displayNumber($serviceRequest);

        return match ($serviceRequest->status) {
            RequestStatus::Submitted, RequestStatus::QuotationRejected => [
                ['text' => '✅ أوافق على المتابعة', 'callback_data' => "reqack:{$ref}"],
                ['text' => '❌ إلغاء الطلب', 'callback_data' => "reqcancel:{$ref}"],
            ],
            RequestStatus::QuotationSent => [
                ['text' => '✅ موافقة على العرض', 'callback_data' => "approve:{$ref}"],
                ['text' => '❌ رفض العرض', 'callback_data' => "reject:{$ref}"],
            ],
            RequestStatus::AwaitingPayment => [
                ['text' => '📎 رفع وصل الدفع', 'callback_data' => "receipt_hint:{$ref}"],
                ['text' => '❌ إلغاء الطلب', 'callback_data' => "reqcancel:{$ref}"],
            ],
            RequestStatus::ReadyForReview => [
                ['text' => '✅ اعتماد التسليم', 'callback_data' => "complete:{$ref}"],
                ['text' => '🔁 طلب تعديل', 'callback_data' => "revision:{$ref}"],
            ],
            default => null,
        };
    }
}
