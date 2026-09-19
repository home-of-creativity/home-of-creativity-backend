<?php

namespace App\Actions;

use App\Enums\EmployeeProfession;
use App\Enums\QuotationDecisionType;
use App\Enums\RequestStatus;
use App\Models\Quotation;
use App\Models\QuotationDecision;
use App\Models\ServiceRequest;
use App\Services\RequestStatusTransitionService;
use App\Support\ResolveServiceRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveQuotation
{
    /** @var array{caption: string, qr_available: bool, delivered: bool}|null */
    public ?array $clientPaymentNotice = null;

    public function __construct(
        private RequestStatusTransitionService $transitions,
        private NotifyEmployees $notifyEmployees,
        private ApplyQuotationAcceptance $applyQuotationAcceptance,
    ) {}

    public function handle(ServiceRequest $request, ?Quotation $quotation = null): ServiceRequest
    {
        if ($request->status !== RequestStatus::QuotationSent) {
            return $request;
        }

        $quotation ??= $request->quotations()->latest('version')->first();
        if (! $quotation) {
            throw ValidationException::withMessages(['quotation' => 'No quotation found.']);
        }

        $updated = DB::transaction(function () use ($request, $quotation): ServiceRequest {
            QuotationDecision::query()->firstOrCreate(
                [
                    'request_id' => $request->id,
                    'quotation_id' => $quotation->id,
                    'decision' => QuotationDecisionType::Approved,
                ],
                ['reason' => null],
            );

            $updated = $this->transitions->transition($request, RequestStatus::AwaitingPayment, 'client', 'Quotation approved.');

            $fresh = $updated->fresh(['client', 'pricingPackage']) ?? $updated;
            $displayNumber = ResolveServiceRequest::displayNumber($fresh);
            $this->notifyEmployees->handle(
                $fresh,
                EmployeeProfession::Sales,
                $this->salesDecisionMessage(
                    $fresh,
                    "✅ وافق الزبون على عرض السعر\n#{$displayNumber} — {$fresh->title}\n{$fresh->client?->name}",
                ),
            );

            return $fresh;
        });

        $updated = $this->applyQuotationAcceptance->handle($updated->fresh(['client', 'pricingPackage']) ?? $updated);
        $this->clientPaymentNotice = $this->applyQuotationAcceptance->clientNotice;

        return $updated->fresh(['client', 'invoices', 'pricingPackage']) ?? $updated;
    }

    private function salesDecisionMessage(ServiceRequest $request, string $body): string
    {
        $line = $request->client?->telegramContactLine();

        return filled($line) ? $body."\n\n{$line}" : $body;
    }
}
