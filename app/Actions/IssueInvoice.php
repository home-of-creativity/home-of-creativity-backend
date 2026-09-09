<?php

namespace App\Actions;

use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\ServiceRequest;
use App\Services\BrandedDocument;
use App\Services\OdooClient;
use App\Services\TelegramNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IssueInvoice
{
    public function __construct(
        private BrandedDocument $documents,
        private TelegramNotifier $telegram,
        private OdooClient $odoo,
    ) {}

    public function handle(ServiceRequest $request): Invoice
    {
        return DB::transaction(function () use ($request): Invoice {
            $existing = $request->invoices()->latest('id')->first();
            if ($existing) {
                return $existing;
            }

            $invoiceNumber = 'INV-'.$request->number;
            $amount = (float) ($request->quotation_amount ?? 0);
            $paymentMethod = $request->payment_method?->value ?? PaymentMethod::Cash->value;
            $pdfPath = $this->documents->invoicePdf($request, $invoiceNumber, $amount, $paymentMethod);

            $odooInvoiceId = null;
            if ($this->odoo->configured()) {
                try {
                    $partnerId = $request->client?->odoo_partner_id;
                    if (! $partnerId && $request->client) {
                        $partnerId = $this->odoo->createOrReusePartner(
                            $request->client->name,
                            $request->client->email,
                            $request->client->phone,
                            $request->number,
                        );
                        $request->client->forceFill(['odoo_partner_id' => $partnerId])->save();
                    }
                    if ($partnerId) {
                        $odooInvoiceId = $this->odoo->createInvoice(
                            (string) $partnerId,
                            $request->number,
                            $request->odoo_quotation_id,
                            $amount > 0 ? $amount : null,
                        );
                        $request->forceFill(['odoo_invoice_id' => $odooInvoiceId])->save();
                    }
                } catch (\Throwable $exception) {
                    Log::warning('Odoo invoice failed during IssueInvoice.', [
                        'request' => $request->number,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $invoice = Invoice::query()->create([
                'request_id' => $request->id,
                'invoice_number' => $invoiceNumber,
                'pdf_path' => $pdfPath,
                'issued_at' => now(),
                'payment_method' => $request->payment_method,
                'odoo_invoice_id' => $odooInvoiceId,
            ]);

            $fileId = $this->telegram->sendStoredDocument(
                $request,
                $pdfPath,
                "تم تأكيد الدفع للطلب {$request->number}. مرفق الفاتورة.",
            );
            if ($fileId) {
                $invoice->forceFill(['telegram_file_id' => $fileId])->save();
            }

            if ($request->client?->telegram_user_id) {
                $this->telegram->send(
                    (string) $request->client->telegram_user_id,
                    "تم تأكيد الدفع بنجاح للطلب {$request->number}.",
                );
            }

            return $invoice;
        });
    }
}
