<?php

namespace App\Actions;

use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\ServiceRequest;
use App\Services\OdooClient;
use App\Services\TelegramNotifier;
use App\Support\Money;
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

    public function handle(
        ServiceRequest $request,
        ?float $amount = null,
        string $kind = 'full',
        bool $sendPaidNotice = true,
    ): Invoice {
        return DB::transaction(function () use ($request, $amount, $kind, $sendPaidNotice): Invoice {
            $existing = $kind === 'received'
                ? null
                : $request->invoices()->where('kind', $kind)->latest('id')->first();
            if ($existing) {
                return $existing;
            }

            $resolvedAmount = $amount;
            if ($resolvedAmount === null || $resolvedAmount <= 0) {
                $resolvedAmount = (float) ($request->quotation_amount ?? $request->amount_total ?? 0);
            }

            if ($kind === 'received') {
                $seq = $request->invoices()->count() + 1;
                $invoiceNumber = 'INV-'.$request->number.'-rcv'.$seq;
            } else {
                $suffix = $kind === 'full' ? '' : '-'.$kind;
                $invoiceNumber = 'INV-'.$request->number.$suffix;
            }
            if ($request->invoices()->where('invoice_number', $invoiceNumber)->exists()) {
                $invoiceNumber = 'INV-'.$request->number.'-'.now()->format('His');
            }

            $paymentMethod = $request->payment_method?->value ?? PaymentMethod::Cash->value;
            [$pdfPath, $odooInvoiceId] = $this->resolveInvoicePdf($request, $invoiceNumber, $resolvedAmount, $paymentMethod);

            $invoice = Invoice::query()->create([
                'request_id' => $request->id,
                'invoice_number' => $invoiceNumber,
                'amount' => $resolvedAmount,
                'kind' => $kind,
                'status' => 'issued',
                'pdf_path' => $pdfPath,
                'issued_at' => now(),
                'payment_method' => $request->payment_method,
                'odoo_invoice_id' => $odooInvoiceId,
            ]);

            try {
                if ($pdfPath !== '') {
                    $fileId = $this->telegram->sendStoredDocument(
                        $request,
                        $pdfPath,
                        $this->invoiceCaption($request, $resolvedAmount, $paymentMethod, $kind),
                    );
                    if ($fileId) {
                        $invoice->forceFill(['telegram_file_id' => $fileId])->save();
                    }
                }

                if ($sendPaidNotice && $request->client?->telegram_user_id) {
                    $this->telegram->send(
                        (string) $request->client->telegram_user_id,
                        'عملية الدفع تم بنجاح سيتم العمل على متطلباتكم ويتم المراجعة بأقرب وقت',
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
            if ((bool) config('services.telegram.strict')) {
                throw ValidationException::withMessages([
                    'odoo' => 'Odoo integration is required to issue invoice PDFs.',
                ]);
            }

            Log::warning('Odoo not configured; issuing local invoice without PDF.', [
                'request' => $request->number,
            ]);

            return ['', null];
        }

        try {
            $partnerId = $request->client?->odoo_partner_id;
            if (! $partnerId && $request->client) {
                $partnerId = $this->odoo->createOrReusePartner(
                    $request->client->company_name ?: $request->client->name,
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
            if ((bool) config('services.telegram.strict')) {
                throw $exception;
            }

            Log::warning('Odoo invoice PDF skipped; continuing locally.', [
                'request' => $request->number,
                'error' => $exception->getMessage(),
            ]);

            return ['', null];
        } catch (Throwable $exception) {
            Log::error('Odoo invoice PDF failed.', [
                'request' => $request->number,
                'error' => $exception->getMessage(),
            ]);

            if ((bool) config('services.telegram.strict')) {
                throw ValidationException::withMessages([
                    'odoo' => 'Failed to create or download the Odoo invoice PDF.',
                ]);
            }

            return ['', null];
        }
    }

    private function invoiceCaption(ServiceRequest $request, float $amount, string $paymentMethod, string $kind): string
    {
        $methodLabel = $paymentMethod === PaymentMethod::Receipt->value ? 'وصل بنكي' : 'نقداً';
        $kindLabel = match ($kind) {
            'deposit' => 'دفعة أولى',
            'remaining' => 'المتبقي',
            'renewal' => 'تجديد',
            'received' => 'دفعة مستلمة',
            default => 'كامل المبلغ',
        };

        $total = (float) ($request->amount_total ?? $request->quotation_amount ?? 0);
        $paid = (float) ($request->amount_paid ?? 0);
        $remaining = (float) ($request->amount_remaining ?? max($total - $paid, 0));
        $paidPercent = $request->paidPercent();
        $remainingPercent = $request->remainingPercent();

        $lines = [
            "فاتورة #{$request->number} ({$kindLabel})",
            "العنوان: {$request->title}",
            'هذه الدفعة: '.Money::format($amount),
            'المدفوع: '.Money::format($paid)." ({$paidPercent}%)",
            'المتبقي: '.Money::format($remaining)." ({$remainingPercent}%)",
            "طريقة الدفع: {$methodLabel}",
        ];

        if (filled($request->quotation_notes)) {
            $lines[] = "تفاصيل العرض: {$request->quotation_notes}";
        }

        return implode("\n", $lines);
    }
}
