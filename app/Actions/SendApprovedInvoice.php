<?php

namespace App\Actions;

use App\Models\Invoice;
use App\Models\ServiceRequest;
use App\Services\OdooClient;
use App\Services\TelegramNotifier;
use App\Support\Money;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * When an admin approves a request the client already said yes to, Odoo's invoice wizard
 * turns the quotation into a sales order and bills it; the posted invoice goes to the
 * client on the bot (or by email and WhatsApp) and is kept on the request. A failure
 * leaves the approval standing and returns the reason for the admin.
 */
class SendApprovedInvoice
{
    public function __construct(
        private OdooClient $odoo,
        private PublishOdooPaper $publish,
        private TelegramNotifier $telegram,
    ) {}

    /**
     * @return string|null why no invoice was sent, null when it was
     */
    public function handle(ServiceRequest $request, bool $deliver = true): ?string
    {
        $orderId = (int) $request->odoo_quotation_id;
        $client = $request->client;
        if ($orderId <= 0 || $client === null || ! $this->odoo->configured()) {
            return 'لا يوجد عرض سعر في أودو لهذا الطلب.';
        }
        // A dashboard invoice already posted on this request is the one the client pays;
        // a draft linked to it (a wizard down payment) is posted instead of billing again.
        $linked = (int) ($request->odoo_invoice_id ?? 0);
        if ($linked > 0 && $this->odoo->invoiceAwaitsPayment($linked)) {
            return null;
        }

        try {
            $invoiceId = $linked > 0 && $this->odoo->invoiceOpenForPayment($linked)
                ? $linked
                : $this->odoo->invoiceSaleOrderWizard($orderId, 'delivered', null);
            $onBot = filled($client->telegram_user_id) && $this->telegram->canReachClient($client->telegram_user_id);
            $channel = filled($client->email) ? (filled($client->phone) ? 'both' : 'email') : 'whatsapp';
            $snapshot = $this->publish->invoice($client, $request, ['id' => $invoiceId], $channel, true, null, null, deliver: $deliver && ! $onBot);
            $this->record($request, $snapshot, $this->publish->pdfPath);
            if ($deliver && $onBot) {
                $this->sendOnBot($request, $snapshot, $this->publish->pdfPath);
            }

            return null;
        } catch (Throwable $exception) {
            Log::warning('Approved request invoice was not created.', ['request' => $request->number, 'error' => $exception->getMessage()]);

            return 'تعذر إنشاء الفاتورة في أودو: '.$exception->getMessage();
        }
    }

    /** @param  array<string, mixed>  $snapshot */
    private function record(ServiceRequest $request, array $snapshot, string $pdfPath): void
    {
        $odooId = (string) ($snapshot['id'] ?? '');
        if ($odooId === '' || $request->invoices()->where('odoo_invoice_id', $odooId)->exists()) {
            return;
        }
        $request->invoices()->create([
            'invoice_number' => (string) ($snapshot['name'] ?? 'INV-'.$request->number),
            'amount' => (float) ($snapshot['amount_total'] ?? $request->amount_total ?? 0),
            'kind' => Invoice::kindFor($request, (float) ($snapshot['amount_total'] ?? $request->amount_total ?? 0)),
            'status' => 'issued',
            'pdf_path' => $pdfPath !== '' ? $pdfPath : null,
            'issued_at' => now(),
            'odoo_invoice_id' => $odooId,
        ]);
    }

    /** @param  array<string, mixed>  $snapshot */
    private function sendOnBot(ServiceRequest $request, array $snapshot, string $pdfPath): void
    {
        $name = (string) ($snapshot['name'] ?? '');
        $caption = trim("الفاتورة {$name} للطلب #{$request->number}")."\n"
            .'المبلغ: '.Money::format((float) ($snapshot['amount_total'] ?? $request->amount_total ?? 0));
        try {
            if ($pdfPath !== '') {
                $this->telegram->sendStoredDocument($request, $pdfPath, $caption);
            } else {
                $this->telegram->send((string) $request->client?->telegram_user_id, $caption);
            }
            if ($request->invoice_sent_at === null) {
                $request->forceFill(['invoice_sent_at' => now()])->save();
            }
        } catch (Throwable $exception) {
            Log::warning('Approved request invoice did not reach the bot.', ['request' => $request->number, 'error' => $exception->getMessage()]);
        }
    }
}
