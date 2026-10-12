<?php

namespace App\Actions;

use App\Enums\ClickUpSyncEvent;
use App\Enums\EmployeeProfession;
use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Services\OdooClient;
use App\Services\RequestStatusTransitionService;
use App\Support\ResolveServiceRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A client cancels a request from Telegram or WhatsApp. The request closes locally
 * first; then the open quotation or unpaid invoice is closed in Odoo too. A confirmed
 * sales order stays as it is and sales decide in Odoo.
 */
class CancelClientRequest
{
    private const PAID_STATUSES = [
        RequestStatus::PaymentConfirmed,
        RequestStatus::InProgress,
        RequestStatus::ReadyForReview,
        RequestStatus::RevisionRequested,
        RequestStatus::Completed,
    ];

    public function __construct(
        private RequestStatusTransitionService $transitions,
        private NotifyEmployees $notifyEmployees,
        private OdooClient $odoo,
    ) {}

    /**
     * Why the client may not cancel: `closed` (24 hours after the invoice), `state`, or
     * `paid`. Null when the cancel is allowed.
     */
    public function refusal(ServiceRequest $request): ?string
    {
        if ($request->clientCancelClosed()) {
            return 'closed';
        }
        if (! $request->status->canTransitionTo(RequestStatus::Cancelled)) {
            return 'state';
        }
        if ((float) ($request->amount_paid ?? 0) > 0.009 || in_array($request->status, self::PAID_STATUSES, true)) {
            return 'paid';
        }

        return null;
    }

    public function handle(ServiceRequest $request, string $channel = 'Telegram'): ServiceRequest
    {
        $refusal = $this->refusal($request);
        if ($refusal !== null) {
            throw ValidationException::withMessages(['status' => match ($refusal) {
                'closed' => 'ما فينا نلغي الطلب بعد ٢٤ ساعة من إرسال الفاتورة.',
                'paid' => 'الطلب مدفوع، فما بينلغى من البوت. احكي مع فريق المبيعات.',
                default => 'This request cannot be cancelled in its current state.',
            }]);
        }

        $updated = $this->transitions->transition($request, RequestStatus::Cancelled, 'client', "Client cancelled via {$channel}.");
        $fresh = $updated->fresh('client') ?? $updated;
        $odooNote = $this->closeOdooPapers($fresh, 'client');
        app(SyncClickUpFromStaff::class)->handle($fresh, ClickUpSyncEvent::Cancelled);

        $displayNumber = ResolveServiceRequest::displayNumber($fresh);
        $this->notifyEmployees->handle(
            $fresh,
            EmployeeProfession::Sales,
            "❌ ألغى الزبون الطلب\n#{$displayNumber} — {$fresh->title}\n{$fresh->client?->name}".($odooNote !== null ? "\n\n{$odooNote}" : ''),
        );

        return $fresh->fresh('client') ?? $fresh;
    }

    /**
     * Closes the request's papers in Odoo: an unpaid invoice is cancelled, an unconfirmed
     * quotation is deleted (draft) or cancelled (sent). A confirmed sales order gets a
     * note and is left for sales. Returns a line for sales when Odoo needs their decision.
     * A failure is logged and the local cancel stands, unless TELEGRAM_STRICT is on.
     */
    public function closeOdooPapers(ServiceRequest $request, string $actor): ?string
    {
        if (! $this->odoo->configured()) {
            return null;
        }

        $displayNumber = ResolveServiceRequest::displayNumber($request);
        $who = $actor === 'client' ? 'الزبون' : 'الفريق';
        try {
            $invoiceId = (int) ($request->odoo_invoice_id ?? 0);
            if ($invoiceId > 0) {
                $invoice = $this->odoo->invoiceSnapshot($invoiceId);
                $unpaid = $invoice !== null
                    && in_array($invoice['state'] ?? '', ['draft', 'posted'], true)
                    && ($invoice['payment_state'] ?? 'not_paid') === 'not_paid';
                if ($unpaid) {
                    $this->odoo->cancelInvoice($invoiceId);
                    $request->invoices()->where('odoo_invoice_id', (string) $invoiceId)->update(['status' => 'cancelled']);
                    $request->forceFill(['odoo_invoice_id' => null])->save();
                }
            }

            $orderId = (int) ($request->odoo_quotation_id ?? 0);
            if ($orderId <= 0) {
                return null;
            }
            $result = $this->odoo->removeQuotation($orderId);
            if ($result !== 'sale') {
                return null;
            }

            $this->odoo->messagePost(
                'sale.order',
                $orderId,
                '<span style="color:#c0392b;font-weight:700;">'.e("ألغى {$who} الطلب #{$displayNumber}. أمر البيع مؤكد، فإلغاؤه قرار من أودو.").'</span>',
                true,
            );

            return 'أمر البيع مؤكد في أودو، فلم يُلغَ تلقائيًا. قرّروا إلغاءه من أودو.';
        } catch (Throwable $exception) {
            if ((bool) config('services.telegram.strict')) {
                throw $exception;
            }
            Log::warning('Odoo papers stayed open after the request was cancelled.', [
                'request' => $request->number,
                'error' => $exception->getMessage(),
            ]);

            return 'تعذر إغلاق عرض السعر أو الفاتورة في أودو. راجعوه يدويًا.';
        }
    }
}
