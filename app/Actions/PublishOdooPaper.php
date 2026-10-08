<?php

namespace App\Actions;

use App\Models\Client;
use App\Models\ServiceRequest;
use App\Services\OdooClient;
use Illuminate\Support\Facades\Storage;

class PublishOdooPaper
{
    public function __construct(
        private OdooClient $odoo,
        private DeliverClientDocument $deliver,
    ) {}

    public bool $delivered = false;

    /**
     * @param  array<string, mixed>  $created
     * @return array<string, mixed>
     */
    public function quotation(
        Client $client,
        array $created,
        string $channel,
        ?string $dateOrder,
        ?string $validity,
    ): array {
        $this->delivered = false;
        $id = (int) ($created['id'] ?? 0);
        $this->odoo->writeSaleDates($id, $dateOrder, $validity);
        $this->odoo->markQuotationSent($id);
        $snapshot = $this->odoo->quotationSnapshot($id) ?? $created;
        $relative = $this->storePdf('sale.order', $id, "quotations/paper-{$id}.pdf");
        $name = (string) ($snapshot['name'] ?? '');
        $absolute = $relative !== '' ? Storage::disk('local')->path($relative) : null;
        $this->delivered = $this->deliver->send(
            $client,
            $channel,
            $name !== '' ? "عرض السعر {$name} جاهز من Home of Creativity." : 'عرض السعر جاهز من Home of Creativity.',
            $absolute,
            ($name !== '' ? $name : 'quotation').'.pdf',
        );

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $created
     * @return array<string, mixed>
     */
    public function invoice(
        Client $client,
        ServiceRequest $request,
        array $created,
        string $channel,
        bool $post,
        ?string $invoiceDate,
        ?string $dueDate,
    ): array {
        $this->delivered = false;
        $id = (int) ($created['id'] ?? 0);
        $this->odoo->writeInvoiceDates($id, $invoiceDate, $dueDate);
        if ($post) {
            $this->odoo->postInvoice($id);
        }
        $snapshot = $this->odoo->invoiceSnapshot($id) ?? $created;
        $request->forceFill(['odoo_invoice_id' => (string) $id])->save();
        $relative = $this->storePdf('account.move', $id, "invoices/paper-{$id}.pdf");
        $name = (string) ($snapshot['name'] ?? '');
        $absolute = $relative !== '' ? Storage::disk('local')->path($relative) : null;
        $this->delivered = $this->deliver->send(
            $client,
            $channel,
            $name !== '' ? "الفاتورة {$name} جاهزة من Home of Creativity." : 'الفاتورة جاهزة من Home of Creativity.',
            $absolute,
            ($name !== '' ? $name : 'invoice').'.pdf',
        );

        return $snapshot;
    }

    private function storePdf(string $model, int $id, string $relative): string
    {
        if ($id <= 0) {
            return '';
        }

        $binary = $model === 'sale.order'
            ? $this->odoo->downloadSaleOrderPdf($id)
            : $this->odoo->downloadInvoicePdf($id);
        if (! is_string($binary) || $binary === '') {
            return '';
        }

        Storage::disk('local')->put($relative, $binary);

        return $relative;
    }
}
