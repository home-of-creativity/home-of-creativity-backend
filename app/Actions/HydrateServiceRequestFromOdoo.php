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

        $quotationLive = null;
        $invoiceLive = null;

        try {
            $quotationLive = $this->hydrateQuotation($request);
        } catch (Throwable $exception) {
            Log::warning('Odoo quotation hydrate failed.', [
                'request_id' => $request->id,
                'error' => $exception->getMessage(),
            ]);
        }

        try {
            $invoiceLive = $this->hydrateInvoice($request, is_array($quotationLive) ? $quotationLive : null);
        } catch (Throwable $exception) {
            Log::warning('Odoo invoice hydrate failed.', [
                'request_id' => $request->id,
                'error' => $exception->getMessage(),
            ]);
        }

        $fresh = $request->fresh() ?? $request;
        if (is_array($quotationLive)) {
            $fresh->setAttribute('odoo_quotation_live', $quotationLive);
            $fresh->syncOriginalAttribute('odoo_quotation_live');
        }
        if (is_array($invoiceLive)) {
            $fresh->setAttribute('odoo_invoice_live', $invoiceLive);
            $fresh->syncOriginalAttribute('odoo_invoice_live');
        }

        return $fresh;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function hydrateQuotation(ServiceRequest $request): ?array
    {
        $quotation = null;
        if (filled($request->odoo_quotation_id)) {
            $quotation = $this->odoo->quotationSnapshot((int) $request->odoo_quotation_id);
        }
        if ($quotation === null) {
            $quotation = $this->odoo->findQuotationForRequest($request->number);
        }
        if ($quotation === null) {
            return null;
        }

        $payload = [
            'odoo_quotation_id' => (string) $quotation['id'],
        ];
        if (array_key_exists('amount_total', $quotation)) {
            $payload['quotation_amount'] = $quotation['amount_total'];
        }
        $request->forceFill($payload)->save();

        return $quotation;
    }

    /**
     * @param  array<string, mixed>|null  $quotationLive
     * @return array<string, mixed>|null
     */
    private function hydrateInvoice(ServiceRequest $request, ?array $quotationLive): ?array
    {
        $invoice = null;
        if (filled($request->odoo_invoice_id)) {
            $invoice = $this->odoo->invoiceSnapshot((int) $request->odoo_invoice_id);
        }
        if ($invoice === null) {
            $quotationName = is_array($quotationLive) && filled($quotationLive['name'] ?? null)
                ? (string) $quotationLive['name']
                : null;
            $invoice = $this->odoo->findInvoiceForRequest($request->number, $quotationName);
        }
        if ($invoice === null) {
            return null;
        }

        $request->forceFill([
            'odoo_invoice_id' => (string) $invoice['id'],
        ])->save();

        return $invoice;
    }
}
