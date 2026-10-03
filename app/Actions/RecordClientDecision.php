<?php

namespace App\Actions;

use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\ClientReachability;
use Illuminate\Validation\ValidationException;

/**
 * Staff record what the client said by phone while the client bot cannot
 * reach them. Each decision runs the same action the bot button runs, with a
 * manual:{user} actor so history and Odoo show it was entered on their behalf.
 */
class RecordClientDecision
{
    public const DECISIONS = ['approve', 'reject', 'revision', 'complete'];

    public function __construct(
        private ClientReachability $reachability,
        private ApproveQuotation $approveQuotation,
        private RejectQuotation $rejectQuotation,
        private RequestRevision $requestRevision,
        private CompleteRequest $completeRequest,
    ) {}

    public function handle(ServiceRequest $request, string $decision, ?string $reason, User $user): ServiceRequest
    {
        $request->loadMissing('client');
        if ($this->reachability->botReachable($request->client)) {
            throw ValidationException::withMessages([
                'decision' => 'بوت العميل يعمل، فيسجّل الزبون قراره بنفسه.',
            ]);
        }

        $actor = 'manual:'.$user->id;
        $reason = trim((string) $reason);

        return match ($decision) {
            'approve' => $this->approve($request, $actor),
            'reject' => $this->reject($request, $reason, $actor),
            'revision' => $this->revision($request, $reason, $actor),
            'complete' => $this->complete($request, $actor),
            default => throw ValidationException::withMessages(['decision' => 'قرار غير معروف.']),
        };
    }

    private function approve(ServiceRequest $request, string $actor): ServiceRequest
    {
        $this->requireStatus($request, [RequestStatus::QuotationSent], 'الموافقة متاحة بعد إرسال عرض السعر.');

        return $this->approveQuotation->handle($request, null, $actor);
    }

    private function reject(ServiceRequest $request, string $reason, string $actor): ServiceRequest
    {
        $this->requireStatus($request, [RequestStatus::QuotationSent], 'الرفض متاح بعد إرسال عرض السعر.');
        $this->requireReason($reason);

        return $this->rejectQuotation->handle($request, $reason, null, $actor);
    }

    private function revision(ServiceRequest $request, string $reason, string $actor): ServiceRequest
    {
        $this->requireReason($reason);

        return $this->requestRevision->handle($request, $reason, null, $actor);
    }

    private function complete(ServiceRequest $request, string $actor): ServiceRequest
    {
        $this->requireStatus($request, [RequestStatus::ReadyForReview], 'اعتماد التسليم متاح عندما يكون الطلب بانتظار المراجعة.');

        return $this->completeRequest->handle($request, $actor);
    }

    /**
     * @param  list<RequestStatus>  $allowed
     */
    private function requireStatus(ServiceRequest $request, array $allowed, string $message): void
    {
        if (! in_array($request->status, $allowed, true)) {
            throw ValidationException::withMessages(['decision' => $message]);
        }
    }

    private function requireReason(string $reason): void
    {
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'اكتب ما قاله الزبون.']);
        }
    }
}
