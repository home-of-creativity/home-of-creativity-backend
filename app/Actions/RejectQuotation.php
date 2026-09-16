<?php

namespace App\Actions;

use App\Enums\EmployeeProfession;
use App\Enums\QuotationDecisionType;
use App\Enums\RequestStatus;
use App\Models\Quotation;
use App\Models\QuotationDecision;
use App\Models\ServiceRequest;
use App\Services\OdooClient;
use App\Services\RequestStatusTransitionService;
use App\Support\ResolveServiceRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RejectQuotation
{
    public function __construct(
        private RequestStatusTransitionService $transitions,
        private NotifyEmployees $notifyEmployees,
        private OdooClient $odoo,
    ) {}

    public function handle(ServiceRequest $request, string $reason, ?Quotation $quotation = null): ServiceRequest
    {
        if ($request->status !== RequestStatus::QuotationSent) {
            return $request;
        }

        $quotation ??= $request->quotations()->latest('version')->first();
        if (! $quotation) {
            throw ValidationException::withMessages(['quotation' => 'No quotation found.']);
        }

        $updated = DB::transaction(function () use ($request, $quotation, $reason): ServiceRequest {
            $packageName = $request->pricingPackage?->name_ar
                ?: $request->pricingPackage?->name_en
                ?: $request->title;
            $storedReason = "الباقة: {$packageName}\n{$reason}";

            QuotationDecision::query()->firstOrCreate(
                [
                    'request_id' => $request->id,
                    'quotation_id' => $quotation->id,
                    'decision' => QuotationDecisionType::Rejected,
                ],
                ['reason' => $storedReason],
            );

            $updated = $this->transitions->transition($request, RequestStatus::QuotationRejected, 'client', $storedReason);

            $fresh = $updated->fresh(['client', 'pricingPackage']) ?? $updated;
            $displayNumber = ResolveServiceRequest::displayNumber($fresh);
            $this->notifyEmployees->handle(
                $fresh,
                EmployeeProfession::Sales,
                "❌ رفض الزبون عرض السعر\n#{$displayNumber} — {$fresh->title}\n{$fresh->client?->name}\n\nالسبب: {$storedReason}",
            );

            return $fresh;
        });

        $this->pushRejectionToOdoo($updated, $reason);

        return $updated;
    }

    private function pushRejectionToOdoo(ServiceRequest $request, string $reason): void
    {
        if (! $this->odoo->configured()) {
            return;
        }

        $packageName = $request->pricingPackage?->name_ar
            ?: $request->pricingPackage?->name_en
            ?: $request->title;
        $displayNumber = ResolveServiceRequest::displayNumber($request);
        $plain = "رفض العرض — الطلب #{$displayNumber}\nالباقة: {$packageName}\nالسبب: {$reason}";
        $html = $this->odoo->configured()
            ? '<span style="color:#c0392b;font-weight:700;">'.e($plain).'</span>'
            : $plain;

        try {
            $leadId = (int) ($request->client?->odoo_lead_id ?? 0);
            if ($leadId > 0) {
                $this->odoo->markLeadRejected($leadId, $html);
            }

            $orderId = (int) ($request->odoo_quotation_id ?? 0);
            if ($orderId > 0) {
                $this->odoo->messagePost('sale.order', $orderId, $html, true);
            }
        } catch (\Throwable $exception) {
            Log::warning('Odoo quotation rejection push failed.', [
                'request' => $request->number,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
