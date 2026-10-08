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
        private SendQuotation $sendQuotation,
        private DeliverClientDocument $deliver,
    ) {}

    public bool $delivered = false;

    /**
     * @param  array<string, mixed>  $created
     * @param  list<array{title: string, amount?: float|int|string|null, units?: float|int|string|null, notes?: string|null, discount?: float|int|string|null, display_type?: string|null}>  $lines
     * @return array<string, mixed>
     */
    public function quotation(
        Client $client,
        ServiceRequest $request,
        array $created,
        array $lines,
        string $channel,
        ?string $notes,
        ?string $dateOrder,
        ?string $validity,
    ): array {
        $this->delivered = false;
        $id = (int) ($created['id'] ?? 0);
        $this->odoo->writeSaleDates($id, $dateOrder, $validity);
        $this->odoo->markQuotationSent($id);
        $snapshot = $this->odoo->quotationSnapshot($id) ?? $created;
        $relative = $this->storePdf('sale.order', $id, "quotations/paper-{$id}.pdf");
        $priced = array_values(array_filter(
            $lines,
            fn (array $line): bool => ! in_array($line['display_type'] ?? null, ['line_section', 'line_note'], true),
        ));

        $this->sendQuotation->handle(
            $request,
            $this->total($priced),
            $notes,
            'admin',
            null,
            $priced !== [] ? $priced : null,
            false,
            null,
            [
                'odoo_quotation_id' => (string) $id,
                'odoo_partner_id' => (string) $client->odoo_partner_id,
                'pdf_path' => $relative,
            ],
            $channel,
        );
        $this->delivered = $this->sendQuotation->deliveredToClient;

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

    /**
     * @param  list<array{amount?: float|int|string|null, units?: float|int|string|null, discount?: float|int|string|null}>  $lines
     */
    private function total(array $lines): float
    {
        return (float) collect($lines)->sum(function (array $line): float {
            $discount = min(100, max(0, (float) ($line['discount'] ?? 0)));

            return (float) ($line['amount'] ?? 0) * (float) ($line['units'] ?? 1) * (1 - ($discount / 100));
        });
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
