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

    /** The stored PDF of the last invoice, for the bot to send. */
    public string $pdfPath = '';

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
        ['snapshot' => $snapshot, 'pdf_path' => $relative] = $this->prepareQuotation($created, $dateOrder, $validity);
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
     * Marks the Odoo quotation sent and stores its PDF, without delivering it. The bot
     * quotation flow sends it with the approve and reject buttons.
     *
     * @param  array<string, mixed>  $created
     * @return array{snapshot: array<string, mixed>, pdf_path: string}
     */
    public function prepareQuotation(array $created, ?string $dateOrder, ?string $validity): array
    {
        $id = (int) ($created['id'] ?? 0);
        $this->odoo->writeSaleDates($id, $dateOrder, $validity);
        $this->odoo->markQuotationSent($id);

        return [
            'snapshot' => $this->odoo->quotationSnapshot($id) ?? $created,
            'pdf_path' => $this->storePdf('sale.order', $id, "quotations/paper-{$id}.pdf"),
        ];
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
        bool $deliver = true,
    ): array {
        $this->delivered = false;
        $this->pdfPath = '';
        $id = (int) ($created['id'] ?? 0);
        $this->odoo->writeInvoiceDates($id, $invoiceDate, $dueDate);
        if ($post) {
            $this->odoo->postInvoice($id);
        }
        $snapshot = $this->odoo->invoiceSnapshot($id) ?? $created;
        $request->forceFill(['odoo_invoice_id' => (string) $id])->save();
        $relative = $this->storePdf('account.move', $id, "invoices/paper-{$id}.pdf");
        $this->pdfPath = $relative;
        if (! $deliver) {
            return $snapshot;
        }
        $name = (string) ($snapshot['name'] ?? '');
        $absolute = $relative !== '' ? Storage::disk('local')->path($relative) : null;
        $this->delivered = $this->deliver->send(
            $client,
            $channel,
            $name !== '' ? "الفاتورة {$name} جاهزة من Home of Creativity." : 'الفاتورة جاهزة من Home of Creativity.',
            $absolute,
            ($name !== '' ? $name : 'invoice').'.pdf',
        );
        if ($this->delivered && $request->invoice_sent_at === null) {
            $request->forceFill(['invoice_sent_at' => now()])->save();
        }

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
