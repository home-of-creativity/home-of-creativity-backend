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

/**
 * A client's yes to a quotation (bot, app, Odoo portal, or entered by staff after a call)
 * waits for an admin: the request sits in awaiting_admin_approval and admins get a button.
 * Only the admin's confirmation turns the Odoo quotation into a sales order and sends the
 * client the payment steps. An admin acting directly (posting an invoice) skips the wait.
 */
class ApproveQuotation
{
    /** @var array{caption: string, qr_available: bool, delivered: bool}|null */
    public ?array $clientPaymentNotice = null;

    /** True when the last handle() left the approval waiting for an admin. */
    public bool $heldForAdmin = false;

    public function __construct(
        private RequestStatusTransitionService $transitions,
        private ApplyQuotationAcceptance $applyQuotationAcceptance,
        private NotifyPaymentStage $notifyPaymentStage,
        private NotifyEmployees $notifyEmployees,
        private NotifyClientChannels $notifyClient,
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

        DB::transaction(function () use ($request, $quotation): void {
            QuotationDecision::query()->firstOrCreate(
                [
                    'request_id' => $request->id,
                    'quotation_id' => $quotation->id,
                    'decision' => QuotationDecisionType::Approved,
                ],
                ['reason' => null],
            );
        });

        if (self::needsAdmin($actor)) {
            return $this->hold($request, $actor);
        }

        return $this->accept($request, $actor, 'Quotation approved.', '✅ وافق الزبون على عرض السعر');
    }

    /** The admin confirms the client's approval: sales order, payment plan, and payment steps. */
    public function confirm(ServiceRequest $request, string $actor): ServiceRequest
    {
        $this->clientPaymentNotice = null;
        if ($request->status !== RequestStatus::AwaitingAdminApproval) {
            throw ValidationException::withMessages(['request' => 'الطلب ليس بانتظار اعتماد الإدارة.']);
        }

        $updated = $this->accept($request, $actor, 'Client approval confirmed by an admin.', '✅ اعتمدت الإدارة موافقة الزبون');
        // Telegram clients already got the QR from ApplyQuotationAcceptance; others get the same steps on their channel.
        $notice = $this->clientPaymentNotice;
        if (is_array($notice) && ! $notice['delivered']) {
            $this->notifyClient->send($updated, $notice['caption']);
        }

        return $updated;
    }

    /** The admin does not confirm: the quotation counts as rejected so staff can send a new one. */
    public function decline(ServiceRequest $request, string $reason, string $actor): ServiceRequest
    {
        if ($request->status !== RequestStatus::AwaitingAdminApproval) {
            throw ValidationException::withMessages(['request' => 'الطلب ليس بانتظار اعتماد الإدارة.']);
        }
        $updated = $this->transitions->transition($request, RequestStatus::QuotationRejected, $actor, 'Admin did not confirm the client approval: '.$reason);
        $updated = $updated->fresh(['client']) ?? $updated;
        $number = ResolveServiceRequest::displayNumber($updated);
        $this->notifyClient->send($updated, "راجع الفريق عرض السعر للطلب #{$number}، وسيصلك عرض محدّث قريباً.");

        return $updated;
    }

    /** Client and staff-entered approvals wait; the dashboard and the Odoo invoice path act as admin. */
    public static function needsAdmin(string $actor): bool
    {
        return $actor === 'client' || str_starts_with($actor, 'client:') || str_starts_with($actor, 'manual:');
    }

    private function hold(ServiceRequest $request, string $actor): ServiceRequest
    {
        $updated = $this->transitions->transition($request, RequestStatus::AwaitingAdminApproval, $actor, 'Client approved the quotation; waiting for an admin.');
        $updated = $updated->fresh(['client', 'pricingPackage']) ?? $updated;
        $this->heldForAdmin = true;

        $number = ResolveServiceRequest::displayNumber($updated);
        $amount = (float) ($updated->quotation_amount ?? $updated->amount_total ?? 0);
        $lines = array_filter([
            '🟡 وافق الزبون على عرض السعر وينتظر اعتمادك',
            "#{$number} — {$updated->title}",
            trim(($updated->client?->name ?? '').($updated->client?->company_name ? ' — '.$updated->client->company_name : '')),
            $amount > 0 ? 'المبلغ: '.number_format($amount, 2).' USD' : null,
            'بعد الاعتماد يتحول العرض إلى أمر بيع ويصل الزبون طلب الدفع.',
        ]);
        // Sales hear about the yes now; the payment-stage message with its button follows the admin's confirmation.
        $this->notifyEmployees->handle(
            $updated,
            EmployeeProfession::Sales,
            $this->salesDecisionMessage($updated, "🟡 وافق الزبون على عرض السعر — بانتظار اعتماد الإدارة\n#{$number} — {$updated->title}\n{$updated->client?->name}"),
        );
        $this->notifyEmployees->handleAdmins(implode("\n", $lines), [
            ['text' => '✅ اعتماد', 'callback_data' => 'qok:'.$number],
            ['text' => '📋 فتح الطلب', 'url' => rtrim((string) config('app.url'), '/').'/dashboard/requests/'.$updated->id],
        ]);

        return $updated;
    }

    private function accept(ServiceRequest $request, string $actor, string $note, string $headline): ServiceRequest
    {
        $updated = $this->transitions->transition($request, RequestStatus::AwaitingPayment, $actor, $note);
        $updated = $updated->fresh(['client', 'pricingPackage']) ?? $updated;

        $this->confirmSaleOrder($updated);
        $updated = $this->applyQuotationAcceptance->handle($updated->fresh(['client', 'pricingPackage']) ?? $updated);
        $this->clientPaymentNotice = $this->applyQuotationAcceptance->clientNotice;
        $fresh = $updated->fresh(['client', 'pricingPackage']) ?? $updated;
        $displayNumber = ResolveServiceRequest::displayNumber($fresh);
        $this->notifyPaymentStage->handle(
            $fresh,
            null,
            $this->salesDecisionMessage($fresh, "{$headline}\n#{$displayNumber} — {$fresh->title}\n{$fresh->client?->name}"),
        );

        return $updated->fresh(['client', 'invoices', 'pricingPackage']) ?? $updated;
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
