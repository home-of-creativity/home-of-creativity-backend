<?php

namespace App\Actions;

use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\ServiceRequest;
use App\Services\OdooClient;
use App\Services\TelegramNotifier;
use App\Support\CorrespondenceDocument;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class IssueInvoice
{
    /** Why the last payment did not reach Odoo, for the staff member who confirmed it. */
    public ?string $error = null;

    /** @var array<string, mixed>|null the Odoo invoice the last payment went on, after posting */
    public ?array $odooInvoice = null;

    public function __construct(
        private TelegramNotifier $telegram,
        private OdooClient $odoo,
        private AlertTelegramDeliveryFailure $alertTelegramDeliveryFailure,
        private CorrespondenceDocument $letters,
    ) {}

    public function handle(
        ServiceRequest $request,
        ?float $amount = null,
        string $kind = 'full',
        bool $sendPaidNotice = true,
    ): Invoice {
        $this->error = null;
        $this->odooInvoice = null;
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
        // Odoo is called before the local write so a slow or failing Odoo holds no lock.
        [$pdfPath, $odooInvoiceId] = $this->resolveInvoicePdf($request, $invoiceNumber, $resolvedAmount, $kind, $paymentMethod);

        $invoice = DB::transaction(function () use ($request, $invoiceNumber, $resolvedAmount, $kind, $pdfPath, $odooInvoiceId): Invoice {
            $odoo = $this->odooInvoice;
            $status = match ($odoo['payment_state'] ?? null) {
                'paid', 'in_payment' => 'paid',
                'partial' => 'partial',
                default => 'issued',
            };
            $same = filled($odooInvoiceId)
                ? $request->invoices()->where('odoo_invoice_id', (string) $odooInvoiceId)->latest('id')->first()
                : null;
            if ($same !== null) {
                $same->forceFill([
                    'status' => $status,
                    'pdf_path' => $pdfPath !== '' ? $pdfPath : $same->pdf_path,
                    'payment_method' => $request->payment_method,
                ])->save();

                return $same;
            }

            $odooName = (string) ($odoo['name'] ?? '');
            $number = $odooName !== '' && $odooName !== '/' && ! Invoice::query()->where('invoice_number', $odooName)->exists() ? $odooName : $invoiceNumber;

            return Invoice::query()->create([
                'request_id' => $request->id,
                'invoice_number' => $number,
                'amount' => $odoo !== null ? (float) ($odoo['amount_total'] ?? $resolvedAmount) : $resolvedAmount,
                'kind' => $odoo !== null && $kind === 'received' ? Invoice::kindFor($request, (float) ($odoo['amount_total'] ?? $resolvedAmount)) : $kind,
                'status' => $odoo !== null ? $status : 'issued',
                'pdf_path' => $pdfPath,
                'issued_at' => now(),
                'payment_method' => $request->payment_method,
                'odoo_invoice_id' => $odooInvoiceId,
            ]);
        });
        if ($request->invoice_sent_at === null) {
            $request->forceFill(['invoice_sent_at' => now()])->save();
        }

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
            if ($this->telegram->canReachClient($request->client?->telegram_user_id)) {
                $this->alertTelegramDeliveryFailure->handle($request, 'invoice', $exception->getMessage());
            }
        }

        return $invoice;
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function resolveInvoicePdf(
        ServiceRequest $request,
        string $invoiceNumber,
        float $amount,
        string $kind,
        string $paymentMethod,
    ): array {
        if (! $this->odoo->configured()) {
            return [$this->localInvoicePdf($request, $invoiceNumber, $amount, $kind, $paymentMethod), null];
        }

        $odooInvoiceId = null;

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

            $odooInvoiceId = $this->invoiceForPayment($request, (string) $partnerId, $amount);
            $request->forceFill(['odoo_invoice_id' => $odooInvoiceId])->save();

            try {
                $this->odoo->postAndPayInvoice($odooInvoiceId, $amount > 0 ? $amount : null);
            } catch (Throwable $exception) {
                $this->error = 'لم تُرحّل الفاتورة أو لم تُسجّل الدفعة في أودو: '.$exception->getMessage();
                Log::warning('Odoo invoice was created but not posted or paid.', [
                    'request' => $request->number,
                    'odoo_invoice_id' => $odooInvoiceId,
                    'error' => $exception->getMessage(),
                ]);
            }
            $this->odooInvoice = $this->odoo->invoiceSnapshot((int) $odooInvoiceId);

            $pdfBinary = $this->odoo->downloadInvoicePdf($odooInvoiceId);
            if (! is_string($pdfBinary) || $pdfBinary === '') {
                throw ValidationException::withMessages([
                    'odoo' => 'Odoo returned an empty invoice PDF.',
                ]);
            }

            $relativePath = "invoices/{$invoiceNumber}-odoo.pdf";
            Storage::disk('local')->put($relativePath, $pdfBinary);

            return [$relativePath, $odooInvoiceId];
        } catch (Throwable $exception) {
            if (blank($odooInvoiceId)) {
                $this->error ??= 'لم تُنشأ الفاتورة في أودو: '.$exception->getMessage();
            }
            Log::warning('Odoo invoice PDF skipped; using the correspondence template.', [
                'request' => $request->number,
                'error' => $exception->getMessage(),
            ]);

            return [
                $this->localInvoicePdf($request, $invoiceNumber, $amount, $kind, $paymentMethod),
                filled($odooInvoiceId) ? (string) $odooInvoiceId : null,
            ];
        }
    }

    /**
     * The Odoo invoice this payment goes on, so the client is never billed twice: the
     * request's invoice while it has something left to pay (a draft is posted first), else
     * the rest of the sales order through Odoo's invoice wizard, and only without an order
     * a standalone invoice.
     */
    private function invoiceForPayment(ServiceRequest $request, string $partnerId, float $amount): string
    {
        if (filled($request->odoo_invoice_id) && $this->odoo->invoiceOpenForPayment((int) $request->odoo_invoice_id)) {
            return (string) $request->odoo_invoice_id;
        }

        $orderId = (int) ($request->odoo_quotation_id ?? 0);
        if ($orderId > 0) {
            try {
                return (string) $this->odoo->invoiceSaleOrderWizard($orderId, 'delivered', null);
            } catch (Throwable $exception) {
                // An order already invoiced must not be billed again.
                try {
                    $invoiced = $this->odoo->saleOrderInvoiced($orderId);
                } catch (Throwable) {
                    $invoiced = 0.0;
                }
                if ($invoiced > 0.009) {
                    throw $exception;
                }
                Log::warning('Odoo invoice wizard failed; a standalone invoice takes the payment.', [
                    'request' => $request->number,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $this->odoo->createInvoice($partnerId, $request->number, $request->odoo_quotation_id, $amount > 0 ? $amount : null);
    }

    private function localInvoicePdf(
        ServiceRequest $request,
        string $invoiceNumber,
        float $amount,
        string $kind,
        string $paymentMethod,
    ): string {
        try {
            return $this->letters->invoice(
                $request,
                $invoiceNumber,
                $amount,
                $kind,
                $paymentMethod,
                "invoices/{$invoiceNumber}-local.pdf",
            );
        } catch (Throwable $exception) {
            Log::error('Correspondence invoice PDF failed.', [
                'request' => $request->number,
                'error' => $exception->getMessage(),
            ]);

            if ((bool) config('services.telegram.strict')) {
                throw ValidationException::withMessages([
                    'pdf' => 'تعذر إنشاء ملف الفاتورة.',
                ]);
            }

            return '';
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
