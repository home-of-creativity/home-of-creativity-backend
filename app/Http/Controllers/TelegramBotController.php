<?php

namespace App\Http\Controllers;

use App\Actions\ApproveDriveDelivery;
use App\Actions\ApproveQuotation;
use App\Actions\ClientAssistant;
use App\Actions\CompleteRequest;
use App\Actions\CreateCatalogRequest;
use App\Actions\DeclineRenewal;
use App\Actions\HandleWhatsAppInbound;
use App\Actions\NotifyEmployees;
use App\Actions\NotifyPaymentStage;
use App\Actions\ProvisionSalesClickUpTask;
use App\Actions\PushClientLeadToOdoo;
use App\Actions\PushClientToOdoo;
use App\Actions\RejectQuotation;
use App\Actions\RenewSubscription;
use App\Actions\RequestRevision;
use App\Actions\ResolveTelegramClient;
use App\Actions\SubmitServiceRequest;
use App\Actions\SyncClickUpFromStaff;
use App\Enums\ClickUpSyncEvent;
use App\Enums\EmployeeProfession;
use App\Enums\RequestStatus;
use App\Http\Requests\TelegramLinkRequest;
use App\Http\Requests\TelegramSubmitRequest;
use App\Http\Resources\ClientResource;
use App\Http\Resources\ServiceRequestResource;
use App\Models\Client;
use App\Models\DriveDelivery;
use App\Models\PricingPackage;
use App\Models\RequestFile;
use App\Models\ServiceRequest;
use App\Models\SupportMessage;
use App\Services\ClickUpStatusMapper;
use App\Services\OdooLeadLog;
use App\Services\RequestStatusTransitionService;
use App\Support\ChatLanguage;
use App\Support\ClientProfileValue;
use App\Support\PricingCatalog;
use App\Support\ResolveServiceRequest;
use App\Support\ShamCashQr;
use App\Support\StatusLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TelegramBotController extends Controller
{
    public function __construct(private ResolveTelegramClient $resolveTelegramClient) {}

    public function link(TelegramLinkRequest $request): JsonResponse
    {
        $payload = [
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'phone' => $request->validated('phone'),
            'locale' => $request->validated('locale') ?? 'ar',
        ];
        if ($request->filled('company_name')) {
            $payload['company_name'] = $request->validated('company_name');
        }

        $client = $this->resolveTelegramClient->link(
            $request->validated('telegram_user_id'),
            $payload,
        );

        return response()->json([
            'data' => $this->profilePayload($client),
            'message' => 'Linked.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $client = $this->resolveTelegramClient->handle($request->query('telegram_user_id'));

        return response()->json([
            'data' => $this->profilePayload($client),
            'message' => 'ok',
        ]);
    }

    public function updateProfile(Request $request, PushClientToOdoo $pushClientToOdoo, PushClientLeadToOdoo $pushClientLeadToOdoo): JsonResponse
    {
        $validated = $request->validate([
            'telegram_user_id' => ['required', 'string', 'max:40'],
            'name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['sometimes', 'string', 'max:40'],
            'company_name' => ['sometimes', 'string', 'max:160'],
            'email' => ['sometimes', 'string', 'max:255'],
            'email_skipped' => ['sometimes', 'boolean'],
            'company_activity' => ['sometimes', 'string', 'max:255'],
        ]);

        $client = $this->resolveTelegramClient->handle($validated['telegram_user_id']);

        $client->forceFill($this->profileValues($validated, $client))->save();

        $client = $client->fresh() ?? $client;
        $this->pushCompletedClientToOdoo($client, $pushClientToOdoo, $pushClientLeadToOdoo);
        $client = $client->fresh() ?? $client;

        return response()->json([
            'data' => $this->profilePayload($client),
            'message' => 'Updated.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function profilePayload(Client $client): array
    {
        return array_merge(ClientResource::make($client)->resolve(), [
            'profile_complete' => $client->profileComplete(),
            'missing_fields' => $client->pendingProfileFields(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function profileValues(array $validated, Client $client): array
    {
        $values = [];

        if (array_key_exists('name', $validated)) {
            $values['name'] = ClientProfileValue::usableName(trim((string) $validated['name']))
                ?? throw ValidationException::withMessages(['name' => 'أرسل اسمك الكامل، وليس رقماً أو زر قائمة.']);
        }

        if (array_key_exists('phone', $validated)) {
            $values['phone'] = ClientProfileValue::usablePhone(trim((string) $validated['phone']))
                ?? throw ValidationException::withMessages(['phone' => 'أرسل رقم هاتف صالح، مثل 0991234567.']);
        }

        if (array_key_exists('company_name', $validated)) {
            $values['company_name'] = ClientProfileValue::usableCompanyName(trim((string) $validated['company_name']), $client->telegram_user_id)
                ?? throw ValidationException::withMessages(['company_name' => 'أرسل اسم الشركة الحقيقي.']);
        }

        if (array_key_exists('email', $validated)) {
            if (ClientProfileValue::isNoEmailAnswer((string) $validated['email'])) {
                $values['email_skipped_at'] = $client->email_skipped_at ?? now();
            } else {
                $values['email'] = ClientProfileValue::usableEmail((string) $validated['email'])
                    ?? throw ValidationException::withMessages(['email' => 'أرسل بريداً إلكترونياً صالحاً، أو اضغط «لا يوجد بريد».']);
                $values['email_skipped_at'] = null;
            }
        }

        if (($validated['email_skipped'] ?? false) === true && ! filled($client->email)) {
            $values['email_skipped_at'] = $client->email_skipped_at ?? now();
        }

        if (array_key_exists('company_activity', $validated)) {
            $values['company_activity'] = ClientProfileValue::usableActivity((string) $validated['company_activity'])
                ?? throw ValidationException::withMessages(['company_activity' => 'اكتب نشاط الشركة بكلمات قليلة، مثل: مطعم أو عيادة.']);
        }

        return $values;
    }

    public function submit(TelegramSubmitRequest $request, SubmitServiceRequest $submitServiceRequest): JsonResponse
    {
        $client = $this->resolveTelegramClient->handle($request->validated('telegram_user_id'));

        abort_unless($client->profileComplete(), 422, 'Complete your name, phone, and company first.');

        $serviceRequest = $submitServiceRequest->handle($client, [
            'title' => $request->validated('title'),
            'description' => trim((string) ($request->validated('description') ?? '')) ?: 'انظر المرفقات.',
            'source' => $client->requestSource(),
            'attachments' => $request->validated('attachments') ?? [],
        ]);

        $fresh = $serviceRequest->fresh() ?? $serviceRequest;

        return response()->json([
            'data' => array_merge(ServiceRequestResource::make($fresh)->resolve(), [
                'status_label' => $fresh->status->labelAr(),
            ]),
            'message' => 'Created.',
        ], 201);
    }

    public function index(Request $request, ClickUpStatusMapper $mapper): JsonResponse
    {
        $client = $this->resolveTelegramClient->handle($request->query('telegram_user_id'));

        $items = $client->requests()
            ->with('pricingPackage')
            ->withCount('driveDeliveries')
            ->latest('id')
            ->get()
            ->reject(fn (ServiceRequest $item) => $item->hiddenFromClient())
            ->map(fn (ServiceRequest $item) => [
                'number' => $item->number,
                'display_number' => ResolveServiceRequest::displayNumber($item),
                'title' => $item->title,
                'status' => $item->status->value,
                'status_label' => StatusLabel::requestAr($item->status->value),
                'execution_status' => $item->execution_status?->value,
                'execution_status_label' => $mapper->toClientLabel($item->execution_status) ?: StatusLabel::requestAr($item->status->value),
                'package_name' => $item->pricingPackage?->name_ar ?: $item->pricingPackage?->name_en,
                'billing_period' => $item->billing_period,
                'amount_paid' => $item->amount_paid,
                'amount_remaining' => $item->amount_remaining,
                'amount_total' => $item->amount_total ?? $item->quotation_amount,
                'allows_renewal' => (bool) $item->allows_renewal,
                'can_renew' => $item->canRenew(),
                'duration_label' => $item->durationLabel(),
                'show_subscription' => ! $item->renewalDeclined(),
                'can_edit' => $item->status->allowsClientEdit(),
                'can_revise' => $item->allowsClientRevision(),
                'can_complete' => $item->status === RequestStatus::ReadyForReview,
                'receipt_reupload_required' => (bool) $item->receipt_reupload_required,
                'accepts_receipt' => $item->acceptsReceiptUpload(),
            ]);

        return response()->json(['data' => $items, 'message' => 'ok']);
    }

    public function update(Request $request, ServiceRequest $serviceRequest): ServiceRequestResource
    {
        $client = $this->resolveTelegramClient->handle($request->input('telegram_user_id'));

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
        $additional = ['message' => 'Approved.'];
        if (is_array($approveQuotation->clientPaymentNotice)) {
            $additional['sham_cash_qr'] = ShamCashQr::toBotPayload($approveQuotation->clientPaymentNotice);
        }

        return ServiceRequestResource::make($updated->fresh('client'))
            ->additional($additional);
    }

    public function shamCashQr(): BinaryFileResponse
    {
        $absolute = ShamCashQr::absolutePath();
        abort_unless(filled($absolute), 404, 'Sham Cash QR is not configured.');

        return response()->file($absolute, [
            'Content-Type' => mime_content_type($absolute) ?: 'image/png',
            'Content-Disposition' => 'inline; filename="'.basename($absolute).'"',
        ]);
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
        NotifyPaymentStage $notifyPaymentStage,
        ProvisionSalesClickUpTask $provisionSalesClickUpTask,
    ): JsonResponse {
        $this->assertClientOwns($request, $serviceRequest);
        abort_unless($serviceRequest->acceptsReceiptUpload(), 422, 'Receipt upload is only allowed while awaiting payment.');

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

        $serviceRequest->forceFill([
            'receipt_reupload_required' => false,
            'receipt_reupload_reason' => null,
        ])->save();

        $serviceRequest->load('client');
        $displayNumber = ResolveServiceRequest::displayNumber($serviceRequest);
        $notifyPaymentStage->handle(
            $serviceRequest,
            $receiptFile,
            "📎 رفع الزبون وصل دفع\n#{$displayNumber} — {$serviceRequest->title}\n{$serviceRequest->client?->name}",
        );

        $provisionSalesClickUpTask->appendReceipt($serviceRequest, $receiptFile);
        app(OdooLeadLog::class)->receiptUploaded($serviceRequest);

        return response()->json([
            'data' => ['stored' => true],
            'message' => 'Receipt uploaded.',
        ]);
    }

    public function revision(Request $request, ServiceRequest $serviceRequest, RequestRevision $requestRevision): ServiceRequestResource
    {
        $this->assertClientOwns($request, $serviceRequest);

        $validated = $request->validate([
            'telegram_user_id' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:5000'],
            'drive_delivery_id' => ['nullable', 'integer'],
        ]);

        $delivery = null;
        if (filled($validated['drive_delivery_id'] ?? null)) {
            $delivery = DriveDelivery::query()
                ->where('id', $validated['drive_delivery_id'])
                ->where('request_id', $serviceRequest->id)
                ->first();
            if ($delivery === null) {
                throw ValidationException::withMessages([
                    'drive_delivery_id' => 'هذا الملف لا يتبع هذا الطلب.',
                ]);
            }
        }

        $updated = $requestRevision->handle($serviceRequest, $validated['reason'], $delivery);

        return ServiceRequestResource::make($updated->fresh('client'))
            ->additional(['message' => 'Revision requested.']);
    }

    public function approveFile(Request $request, ServiceRequest $serviceRequest, ApproveDriveDelivery $approveDriveDelivery): JsonResponse
    {
        $this->assertClientOwns($request, $serviceRequest);

        $validated = $request->validate([
            'telegram_user_id' => ['required', 'string'],
            'drive_delivery_id' => ['required', 'integer'],
        ]);

        $delivery = DriveDelivery::query()
            ->where('id', $validated['drive_delivery_id'])
            ->where('request_id', $serviceRequest->id)
            ->first();
        if ($delivery === null) {
            throw ValidationException::withMessages([
                'drive_delivery_id' => 'هذا الملف لا يتبع هذا الطلب.',
            ]);
        }

        $approved = $approveDriveDelivery->handle($serviceRequest, $delivery);
        $fresh = $serviceRequest->fresh() ?? $serviceRequest;

        return response()->json([
            'data' => [
                'approved' => true,
                'drive_delivery_id' => $approved->id,
                'client_approved_at' => $approved->client_approved_at?->toIso8601String(),
                'remaining_unapproved' => $approveDriveDelivery->remainingUnapproved($fresh),
                'can_complete' => $approveDriveDelivery->canComplete($fresh),
            ],
            'message' => 'File approved.',
        ]);
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

    public function supportBrief(): JsonResponse
    {
        return response()->json([
            'data' => [
                'phone' => '0947823488',
                'email' => 'info@hoc.agency',
            ],
            'message' => 'ok',
        ]);
    }

    /**
     * Free text the Telegram client bot did not route. The assistant answers from
     * this client's records, names one bot action, or emails the developer.
     */
    public function assistant(Request $request, ClientAssistant $assistant): JsonResponse
    {
        $validated = $request->validate([
            'telegram_user_id' => ['required', 'string'],
            'message' => ['required', 'string', 'max:2000'],
            'history' => ['nullable', 'array', 'max:10'],
            'history.*.role' => ['required', 'string', 'in:user,assistant'],
            'history.*.text' => ['required', 'string', 'max:500'],
        ]);

        $client = $this->resolveTelegramClient->handle($validated['telegram_user_id']);
        $locale = ChatLanguage::detect($validated['message']) ?? ($client->locale === 'en' ? 'en' : 'ar');
        $decision = $assistant->decide(
            $client,
            $validated['message'],
            array_values($validated['history'] ?? []),
            $locale,
            'idle',
            'telegram',
        );

        $number = null;
        $ref = trim(ltrim($decision['ref'], '#'));
        if ($ref !== '') {
            try {
                $named = app(ResolveServiceRequest::class)->byReference($ref);
                $number = $named->client_id === $client->id ? $named->number : null;
            } catch (\Throwable) {
                $number = null;
            }
        }

        return response()->json([
            'data' => [
                'action' => $decision['action'],
                'answer' => $decision['answer'],
                'request_number' => $number,
            ],
            'message' => 'ok',
        ]);
    }

    public function support(Request $request, NotifyEmployees $notifyEmployees): JsonResponse
    {
        $validated = $request->validate([
            'telegram_user_id' => ['required', 'string'],
            'message' => ['required', 'string', 'max:5000'],
            'request_number' => ['nullable', 'string'],
        ]);

        $client = $this->resolveTelegramClient->handle($validated['telegram_user_id']);

        $serviceRequest = null;
        if (filled($validated['request_number'] ?? null)) {
            $serviceRequest = ServiceRequest::query()
                ->where('number', $validated['request_number'])
                ->where('client_id', $client->id)
                ->first();
        }

        SupportMessage::query()->create([
            'client_id' => $client->id,
            'request_id' => $serviceRequest?->id,
            'message' => $validated['message'],
        ]);

        $who = trim($client->name.($client->company_name ? ' ('.$client->company_name.')' : ''));
        $ref = $serviceRequest?->number;
        $text = "رسالة دعم من {$who}".($ref ? "\n#{$ref}" : '')."\n\n{$validated['message']}";
        if ($serviceRequest) {
            $notifyEmployees->handle($serviceRequest, EmployeeProfession::Sales, $text);
        } else {
            $notifyEmployees->handlePlain(EmployeeProfession::Sales, $text);
        }

        $reply = 'رقم الدعم: 0947823488';

        return response()->json(['data' => ['stored' => true], 'message' => $reply]);
    }

    public function catalog(Request $request, PricingCatalog $catalog): JsonResponse
    {
        $validated = $request->validate([
            'telegram_user_id' => ['required', 'string'],
            'category_id' => ['nullable', 'integer'],
            'subcategory_id' => ['nullable', 'integer'],
            'package_id' => ['nullable', 'integer'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $client = Client::findForTelegram($validated['telegram_user_id']);
        abort_if($client === null, 404);

        // A missing Odoo lead is retried after the JSON is sent, so browsing the catalog
        // does not wait on Odoo and the bot does not time out.
        if ($client->readyForOdoo() && blank($client->odoo_lead_id)) {
            $telegramUserId = (string) $client->telegram_user_id;
            app()->terminating(function () use ($telegramUserId): void {
                app(ResolveTelegramClient::class)->handle($telegramUserId);
            });
        }

        $offset = (int) ($validated['offset'] ?? 0);
        $items = [];
        $kind = 'categories';

        if (filled($validated['package_id'] ?? null)) {
            $kind = 'periods';
            $items = [$catalog->packagePeriods((int) $validated['package_id'])];
        } elseif (filled($validated['subcategory_id'] ?? null)) {
            $kind = 'packages';
            $items = $catalog->subcategoryPackages((int) $validated['subcategory_id']);
        } elseif (filled($validated['category_id'] ?? null)) {
            $items = $catalog->categoryChildren((int) $validated['category_id']);
            $kind = $items[0]['type'] ?? 'packages';
        } else {
            $items = $catalog->categories();
        }

        $page = array_slice($items, $offset, PricingCatalog::PAGE_SIZE);
        $nextOffset = $offset + PricingCatalog::PAGE_SIZE;

        return response()->json([
            'data' => [
                'kind' => $kind,
                'items' => $page,
                'has_more' => $nextOffset < count($items),
                'next_offset' => $nextOffset,
            ],
            'message' => 'ok',
        ]);
    }

    public function catalogRequest(Request $request, CreateCatalogRequest $createCatalogRequest): JsonResponse
    {
        $validated = $request->validate([
            'telegram_user_id' => ['required', 'string'],
            'package_id' => ['required', 'integer', 'exists:pricing_packages,id'],
            'billing_period' => ['nullable', 'string', 'max:40'],
        ]);

        $client = $this->resolveTelegramClient->handle($validated['telegram_user_id']);

        abort_unless($client->profileComplete(), 422, 'Complete your name, phone, and company first.');

        $package = PricingPackage::query()->with('subcategory.category')->findOrFail($validated['package_id']);
        $serviceRequest = $createCatalogRequest->handle($client, $package, $validated['billing_period'] ?? null);
        $fresh = $serviceRequest->fresh() ?? $serviceRequest;

        return response()->json([
            'data' => array_merge(ServiceRequestResource::make($fresh)->resolve(), [
                'status_label' => $fresh->status->labelAr(),
                'quotation_delivered' => $createCatalogRequest->quotationDelivered,
            ]),
            'message' => 'Created.',
        ], 201);
    }

    public function renew(Request $request, ServiceRequest $serviceRequest, RenewSubscription $renewSubscription): ServiceRequestResource
    {
        $this->assertClientOwns($request, $serviceRequest);
        $updated = $renewSubscription->handle($serviceRequest);

        return ServiceRequestResource::make($updated->fresh(['client', 'subscriptions']))
            ->additional(['message' => 'Renewal started.']);
    }

    public function declineRenewal(Request $request, ServiceRequest $serviceRequest, DeclineRenewal $declineRenewal): ServiceRequestResource
    {
        $this->assertClientOwns($request, $serviceRequest);
        $updated = $declineRenewal->handle($serviceRequest);

        return ServiceRequestResource::make($updated)
            ->additional(['message' => 'Renewal declined.']);
    }

    private function pushCompletedClientToOdoo(
        Client $client,
        PushClientToOdoo $pushClientToOdoo,
        PushClientLeadToOdoo $pushClientLeadToOdoo,
    ): void {
        if (! $client->readyForOdoo() && ! filled($client->odoo_lead_id)) {
            return;
        }

        $fresh = $client->fresh() ?? $client;
        $fresh = $pushClientToOdoo->handle($fresh, writeExisting: true);
        $pushClientLeadToOdoo->handle(
            $fresh,
            writeExisting: filled($fresh->odoo_lead_id),
            classifyIndustry: false,
        );
    }

    public function photographyChat(Request $request, HandleWhatsAppInbound $inbound): JsonResponse
    {
        $validated = $request->validate([
            'telegram_user_id' => ['required', 'string', 'max:40'],
            'text' => ['nullable', 'string', 'max:500'],
            'callback' => ['nullable', 'string', 'max:80'],
        ]);
        $client = $this->resolveTelegramClient->handle($validated['telegram_user_id']);
        $handled = $inbound->telegramPhotography(
            $client,
            (string) $client->telegram_user_id,
            $validated['text'] ?? null,
            $validated['callback'] ?? null,
        );

        return response()->json(['handled' => $handled]);
    }

    public function photographySlots(Request $request, ServiceRequest $serviceRequest): JsonResponse
    {
        $this->assertClientOwns($request, $serviceRequest);
        abort(422, 'حجز التصوير غير متاح من المحادثة.');
    }

    public function bookPhotography(Request $request, ServiceRequest $serviceRequest): JsonResponse
    {
        $this->assertClientOwns($request, $serviceRequest);
        $request->validate([
            'telegram_user_id' => ['required', 'string'],
            'starts_at' => ['required', 'date'],
        ]);
        abort(422, 'حجز التصوير غير متاح من المحادثة.');
    }

    public function decidePhotography(Request $request, ServiceRequest $serviceRequest): JsonResponse
    {
        $this->assertClientOwns($request, $serviceRequest);
        $request->validate([
            'telegram_user_id' => ['required', 'string'],
            'accept' => ['required', 'boolean'],
        ]);
        abort(422, 'حجز التصوير غير متاح من المحادثة.');
    }

    private function assertClientOwns(Request $request, ServiceRequest $serviceRequest): void
    {
        $client = $this->resolveTelegramClient->handle($request->input('telegram_user_id'));

        abort_unless($serviceRequest->client_id === $client->id, 403);
    }
}
