<?php

namespace App\Actions;

use App\Enums\QuotationDecisionType;
use App\Enums\RequestStatus;
use App\Models\Quotation;
use App\Models\QuotationDecision;
use App\Models\ServiceRequest;
use App\Services\OdooClient;
use App\Services\RequestStatusTransitionService;
use App\Support\ResolveServiceRequest;
use App\Support\ShamCashQr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * A client's yes to a quotation moves the request to awaiting payment and sends the payment
 * steps straight away. When the yes came from the client (bot, app, Odoo portal, or staff
 * entering a call) it also waits for an admin, out of the client's sight: the admin's
 * approval (or the payment being confirmed) turns the quotation into a sales order and
 * sends the Odoo invoice. An admin acting directly (posting an invoice) is approved at once.
 */
class ApproveQuotation
{
    /** @var array{caption: string, qr_available: bool, delivered: bool}|null */
    public ?array $clientPaymentNotice = null;

    /** True when the last handle() left the request waiting for an admin's approval. */
    public bool $heldForAdmin = false;

    /** Why the invoice was not sent on the last confirm(), null when it was. */
    public ?string $invoiceError = null;

    public function __construct(
        private RequestStatusTransitionService $transitions,
        private ApplyQuotationAcceptance $applyQuotationAcceptance,
        private NotifyPaymentStage $notifyPaymentStage,
        private NotifyEmployees $notifyEmployees,
        private SendApprovedInvoice $sendApprovedInvoice,
        private OdooClient $odoo,
    ) {}

    public function handle(ServiceRequest $request, ?Quotation $quotation = null, string $actor = 'client'): ServiceRequest
    {
        $this->heldForAdmin = false;
        $this->clientPaymentNotice = null;
        if ($request->status !== RequestStatus::QuotationSent) {
            return $request;
        }

        $quotation ??= $request->quotations()->latest('version')->first();
        if (! $quotation) {
            throw ValidationException::withMessages(['quotation' => 'No quotation found.']);
        }

        $held = self::needsAdmin($actor);
        $updated = DB::transaction(function () use ($request, $quotation, $actor, $held): ServiceRequest {
            QuotationDecision::query()->firstOrCreate(
                [
                    'request_id' => $request->id,
                    'quotation_id' => $quotation->id,
                    'decision' => QuotationDecisionType::Approved,
                ],
                ['reason' => null],
            );

            // The client is asked to pay either way; awaiting_admin_approval reads as awaiting payment to them.
            $updated = $held
                ? $this->transitions->transition($request, RequestStatus::AwaitingAdminApproval, $actor, 'Quotation approved; waiting for an admin.')
                : $this->transitions->transition($request, RequestStatus::AwaitingPayment, $actor, 'Quotation approved.');
            $updated->forceFill($held
                ? ['admin_approval_pending_at' => now(), 'admin_approved_at' => null, 'admin_approved_by' => null]
                : ['admin_approved_at' => now(), 'admin_approved_by' => $actor])->save();

            return $updated->fresh(['client', 'pricingPackage']) ?? $updated;
        });

        if (! $held) {
            $this->confirmSaleOrder($updated);
        }
        // The client gets the amount and the payment steps now, whether or not we approved yet.
        $updated = $this->applyQuotationAcceptance->handle($updated->fresh(['client', 'pricingPackage']) ?? $updated);
        $this->clientPaymentNotice = $this->applyQuotationAcceptance->clientNotice;
        $fresh = $updated->fresh(['client', 'pricingPackage']) ?? $updated;
        $displayNumber = ResolveServiceRequest::displayNumber($fresh);
        $headline = $held ? '✅ وافق الزبون على عرض السعر — بانتظار اعتماد الإدارة' : '✅ وافق الزبون على عرض السعر';
        $this->notifyPaymentStage->handle(
            $fresh,
            null,
            $this->salesDecisionMessage($fresh, "{$headline}\n#{$displayNumber} — {$fresh->title}\n{$fresh->client?->name}"),
        );

        if ($held) {
            $this->heldForAdmin = true;
            $this->askAdmins($fresh);
        }

        return $updated->fresh(['client', 'invoices', 'pricingPackage']) ?? $updated;
    }

    /**
     * The admin approves a request the client already said yes to: the Odoo quotation becomes
     * a sales order and its invoice goes to the client. With $deliverInvoice false (payment
     * being confirmed) the invoice is created and the payment step sends it paid.
     */
    public function confirm(ServiceRequest $request, string $actor, bool $deliverInvoice = true): ServiceRequest
    {
        $this->invoiceError = null;
        if (! $request->awaitsAdminApproval()) {
            throw ValidationException::withMessages(['request' => 'هذا الطلب ليس بانتظار اعتماد الإدارة.']);
        }

        $this->markApproved($request, $actor);
        $fresh = $request->fresh(['client', 'pricingPackage']) ?? $request;
        if (filled($fresh->odoo_quotation_id)) {
            $this->invoiceError = $this->sendApprovedInvoice->handle($fresh, $deliverInvoice);
            // Without an invoice the order is still confirmed so the work can start.
            if ($this->invoiceError !== null) {
                $this->confirmSaleOrder($fresh);
            }
        }
        $number = ResolveServiceRequest::displayNumber($fresh);
        $this->notifyEmployees->handleAdmins(
            "✅ اعتُمد الطلب #{$number} — {$fresh->title}".($this->invoiceError !== null ? "\n⚠️ {$this->invoiceError}" : ''),
        );

        return $fresh->fresh(['client', 'invoices', 'pricingPackage']) ?? $fresh;
    }

    /**
     * Records the approval. With $confirmOrder the Odoo quotation becomes a sales order too
     * (an admin posting the invoice from the dashboard); no second invoice is made.
     */
    public function markApproved(ServiceRequest $request, string $actor, bool $confirmOrder = false): void
    {
        if ($request->status === RequestStatus::AwaitingAdminApproval) {
            $request = $this->transitions->transition($request, RequestStatus::AwaitingPayment, $actor, 'Approved by an admin.');
        }
        $request->forceFill([
            'admin_approval_pending_at' => $request->admin_approval_pending_at ?? now(),
            'admin_approved_at' => now(),
            'admin_approved_by' => $actor,
        ])->save();
        if ($confirmOrder) {
            $this->confirmSaleOrder($request);
        }
    }

    /** Client and staff-entered approvals wait; the dashboard and the Odoo invoice path act as admin. */
    public static function needsAdmin(string $actor): bool
    {
        return $actor === 'client' || str_starts_with($actor, 'client:') || str_starts_with($actor, 'manual:');
    }

    private function askAdmins(ServiceRequest $request): void
    {
        $number = ResolveServiceRequest::displayNumber($request);
        $amount = (float) ($request->quotation_amount ?? $request->amount_total ?? 0);
        $who = trim(($request->client?->name ?? '').($request->client?->company_name ? ' — '.$request->client->company_name : ''));
        $lines = array_filter([
            '🟡 وافق الزبون على عرض السعر وينتظر اعتمادك',
            "#{$number} — {$request->title}",
            $who !== '' ? $who : null,
            $amount > 0 ? 'المبلغ: '.number_format($amount, 2).' USD' : null,
            'وصلت الزبون خطوات الدفع. بعد الاعتماد يتحول العرض إلى أمر بيع وتصله الفاتورة.',
        ]);
        $this->notifyEmployees->handleAdmins(implode("\n", $lines), [
            ['text' => '✅ اعتماد', 'callback_data' => 'qok:'.$number],
            ['text' => '📋 فتح الطلب', 'url' => rtrim((string) config('app.url'), '/').'/dashboard/requests/'.$request->id],
        ]);

        // Support gets the Sham Cash QR while the team decides. The client is never told about this hold.
        $supportLines = array_filter([
            '🟡 وافق الزبون على عرض السعر — بانتظار اعتماد الفريق',
            "#{$number} — {$request->title}",
            $who !== '' ? $who : null,
            $amount > 0 ? 'المبلغ: '.number_format($amount, 2).' USD' : null,
            'رمز شام كاش للمساعدة عند الحاجة. لا تذكر للزبون انتظار الاعتماد.',
        ]);
        $this->notifyEmployees->supportWithImage(implode("\n", $supportLines), ShamCashQr::absolutePath());
    }

    /** The approved Odoo quotation becomes a sales order; a failure leaves the approval standing. */
    private function confirmSaleOrder(ServiceRequest $request): void
    {
        $id = (int) $request->odoo_quotation_id;
        if ($id <= 0) {
            return;
        }
        try {
            $this->odoo->confirmSaleOrder($id);
        } catch (\Throwable $exception) {
            Log::warning('Odoo quotation was not confirmed as a sales order.', [
                'request' => $request->number,
                'odoo_quotation_id' => $id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function salesDecisionMessage(ServiceRequest $request, string $body): string
    {
        $line = $request->client?->telegramContactLine();

        return filled($line) ? $body."\n\n{$line}" : $body;
    }
}
