<?php

namespace App\Actions;

use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\ServiceRequest;
use App\Services\OdooClient;
use App\Services\TelegramNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class IssueInvoice
{
    public function __construct(
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
            [$pdfPath, $odooInvoiceId] = $this->resolveInvoicePdf($request, $invoiceNumber, $amount, $paymentMethod);

            $invoice = Invoice::query()->create([
                'request_id' => $request->id,
                'invoice_number' => $invoiceNumber,
                'pdf_path' => $pdfPath,
                'issued_at' => now(),
                'payment_method' => $request->payment_method,
                'odoo_invoice_id' => $odooInvoiceId,
            ]);

            try {
                $fileId = $this->telegram->sendStoredDocument(
                    $request,
                    $pdfPath,
                    $this->invoiceCaption($request, $amount, $paymentMethod),
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
            } catch (Throwable $exception) {
                if ((bool) config('services.telegram.strict')) {
                    throw $exception;
                }

                Log::warning('Telegram invoice delivery failed; invoice was still saved.', [
                    'request' => $request->number,
                    'error' => $exception->getMessage(),
                ]);
            }

            return $invoice;
        });
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function resolveInvoicePdf(
        ServiceRequest $request,
        string $invoiceNumber,
        float $amount,
        string $paymentMethod,
    ): array {
        if (! $this->odoo->configured()) {
            throw ValidationException::withMessages([
                'odoo' => 'Odoo integration is required to issue invoice PDFs.',
            ]);
        }

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

            if (! $partnerId) {
                throw ValidationException::withMessages([
                    'odoo' => 'Odoo partner is required before issuing an invoice.',
                ]);
            }

            $odooInvoiceId = $this->odoo->createInvoice(
                (string) $partnerId,
                $request->number,
                $request->odoo_quotation_id,
                $amount > 0 ? $amount : null,
            );
            $request->forceFill(['odoo_invoice_id' => $odooInvoiceId])->save();

            $pdfBinary = $this->odoo->downloadInvoicePdf($odooInvoiceId);
            if (! is_string($pdfBinary) || $pdfBinary === '') {
                throw ValidationException::withMessages([
                    'odoo' => 'Odoo returned an empty invoice PDF.',
                ]);
            }

            $relativePath = "invoices/{$invoiceNumber}-odoo.pdf";
            Storage::disk('local')->put($relativePath, $pdfBinary);

            return [$relativePath, $odooInvoiceId];
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Odoo invoice PDF failed.', [
                'request' => $request->number,
                'error' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'odoo' => 'Failed to create or download the Odoo invoice PDF.',
            ]);
        }
    }

    private function invoiceCaption(ServiceRequest $request, float $amount, string $paymentMethod): string
    {
        $methodLabel = $paymentMethod === PaymentMethod::Receipt->value ? 'وصل بنكي' : 'نقداً';

        $lines = [
            "فاتورة #{$request->number}",
            "العنوان: {$request->title}",
            'المبلغ: '.number_format($amount, 2).' SYP',
            "طريقة الدفع: {$methodLabel}",
        ];

        if (filled($request->quotation_notes)) {
            $lines[] = "تفاصيل العرض: {$request->quotation_notes}";
        }

        $lines[] = '';
        $lines[] = 'تم تأكيد الدفع. مرفق الفاتورة.';

        return implode("\n", $lines);
    }
}
