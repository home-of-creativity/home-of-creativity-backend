<?php

namespace App\Actions;

use App\Models\ServiceRequest;
use App\Services\OdooClient;
use Illuminate\Support\Facades\Log;
use Throwable;

class HydrateServiceRequestFromOdoo
{
    public function __construct(private OdooClient $odoo) {}

    public function handle(ServiceRequest $request): ServiceRequest
    {
        if (! $this->odoo->configured()) {
            return $request;
        }

        try {
            $this->hydrateQuotation($request);
            $this->hydrateInvoice($request);
        } catch (Throwable $exception) {
            Log::warning('Odoo hydrate for service request failed.', [
                'request_id' => $request->id,
                'error' => $exception->getMessage(),
            ]);
        }

        $quotationLive = $request->getAttribute('odoo_quotation_live');
        $invoiceLive = $request->getAttribute('odoo_invoice_live');
        $fresh = $request->fresh() ?? $request;
        if (is_array($quotationLive)) {
            $fresh->setAttribute('odoo_quotation_live', $quotationLive);
        }
        if (is_array($invoiceLive)) {
            $fresh->setAttribute('odoo_invoice_live', $invoiceLive);
        }

        return $fresh;
    }

    private function hydrateQuotation(ServiceRequest $request): void
    {
        $quotation = null;
        if (filled($request->odoo_quotation_id)) {
            $quotation = $this->odoo->quotationSnapshot((int) $request->odoo_quotation_id);
        }
        if ($quotation === null) {
            $quotation = $this->odoo->findQuotationForRequest($request->number);
        }
        if ($quotation === null) {
            return;
        }

        $payload = [
            'odoo_quotation_id' => (string) $quotation['id'],
        ];
        if (array_key_exists('amount_total', $quotation)) {
            $payload['quotation_amount'] = $quotation['amount_total'];
        }
        $request->forceFill($payload)->save();
        $request->setAttribute('odoo_quotation_live', $quotation);
    }

    private function hydrateInvoice(ServiceRequest $request): void
    {
        $invoice = null;
        if (filled($request->odoo_invoice_id)) {
            $invoice = $this->odoo->invoiceSnapshot((int) $request->odoo_invoice_id);
        }
        if ($invoice === null) {
            $invoice = $this->odoo->findInvoiceForRequest($request->number);
        }
        if ($invoice === null) {
            return;
        }

        $request->forceFill([
            'odoo_invoice_id' => (string) $invoice['id'],
        ])->save();
        $request->setAttribute('odoo_invoice_live', $invoice);
    }
}
