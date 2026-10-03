<?php

namespace App\Actions;

use App\Enums\RequestStatus;
use App\Models\Employee;
use App\Models\ServiceRequest;
use App\Services\OdooClient;
use App\Support\BillingPeriod;
use App\Support\CorrespondenceDocument;
use App\Support\Money;
use App\Support\ResolveServiceRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The staff bot shows sales the quotation card and Odoo PDF before anything
 * reaches the client. Sending reuses the same Odoo order and PDF.
 */
class PrepareQuotationPreview
{
    private const TTL_MINUTES = 120;

    public function __construct(
        private OdooClient $odoo,
        private CorrespondenceDocument $letters,
    ) {}

    /**
     * @return array{token: string, card: string, pdf_base64: string|null, file_name: string|null}
     */
    public function prepare(ServiceRequest $request, float $amount, ?string $notes, Employee $employee): array
    {
        if (! in_array($request->status, [RequestStatus::Submitted, RequestStatus::QuotationRejected], true)) {
            throw ValidationException::withMessages([
                'status' => 'يُرسل العرض فقط لطلب جديد أو لعرض مرفوض.',
            ]);
        }
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'أدخل مبلغاً أكبر من صفر.']);
        }

        $request->loadMissing(['client', 'pricingPackage']);
        $token = Str::lower(Str::random(24));
        $notes = filled($notes) ? trim((string) $notes) : null;

        $prepared = [
            'request_id' => $request->id,
            'amount' => round($amount, 2),
            'notes' => $notes,
            'employee_id' => $employee->id,
            'pdf_path' => null,
            'odoo_quotation_id' => null,
            'odoo_partner_id' => null,
        ];

        if ($this->odoo->configured()) {
            try {
                $created = $this->odoo->createQuotation(
                    (string) ($request->client?->company_name ?: $request->client?->name ?? $request->number),
                    $request->client?->email,
                    $request->client?->phone,
                    $request->number,
                    $request->title,
                    $amount,
                    $notes,
                    null,
                    $request->client?->odoo_partner_id,
                    $request->client?->odoo_lead_id,
                );
                $prepared['odoo_quotation_id'] = $created['odoo_quotation_id'];
                $prepared['odoo_partner_id'] = $created['odoo_partner_id'];

                $binary = $this->odoo->downloadSaleOrderPdf($created['odoo_quotation_id']);
                if (is_string($binary) && $binary !== '') {
                    $path = "quotations/preview-{$request->number}-{$token}.pdf";
                    Storage::disk('local')->put($path, $binary);
                    $prepared['pdf_path'] = $path;
                }
            } catch (Throwable $exception) {
                Log::warning('Quotation preview could not build the Odoo PDF.', [
                    'request' => $request->number,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if (! filled($prepared['pdf_path'])) {
            $path = "quotations/preview-{$request->number}-{$token}.pdf";
            $this->letters->quotation($request, $amount, $notes, null, $path);
            $prepared['pdf_path'] = $path;
        }

        Cache::put($this->key($token), $prepared, now()->addMinutes(self::TTL_MINUTES));

        $pdf = filled($prepared['pdf_path']) ? Storage::disk('local')->get((string) $prepared['pdf_path']) : null;

        return [
            'token' => $token,
            'card' => $this->card($request, (float) $prepared['amount'], $notes, filled($prepared['pdf_path'])),
            'pdf_base64' => is_string($pdf) ? base64_encode($pdf) : null,
            'file_name' => is_string($pdf) ? "quotation-{$request->number}.pdf" : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function take(string $token): ?array
    {
        $prepared = Cache::pull($this->key($token));

        return is_array($prepared) ? $prepared : null;
    }

    public function discard(string $token): void
    {
        $prepared = $this->take($token);
        if ($prepared === null) {
            return;
        }

        if (filled($prepared['pdf_path'] ?? null)) {
            Storage::disk('local')->delete((string) $prepared['pdf_path']);
        }
        if (filled($prepared['odoo_quotation_id'] ?? null) && $this->odoo->configured()) {
            try {
                $this->odoo->archiveOrUnlink('sale.order', (string) $prepared['odoo_quotation_id']);
            } catch (Throwable $exception) {
                Log::info('Discarded quotation preview left its Odoo draft.', [
                    'order' => $prepared['odoo_quotation_id'],
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function card(ServiceRequest $request, float $amount, ?string $notes, bool $hasPdf): string
    {
        $package = $request->pricingPackage?->name_ar ?: $request->pricingPackage?->name_en;
        $period = filled($request->billing_period) ? ' — '.BillingPeriod::labelAr((string) $request->billing_period) : '';
        $first = $request->requires_full_payment ? $amount : round($amount / 2, 2);
        $client = trim(($request->client?->name ?? '—').($request->client?->company_name ? ' — '.$request->client->company_name : ''));

        $lines = [
            'معاينة عرض السعر — لم يصل للزبون بعد',
            'الطلب #'.ResolveServiceRequest::displayNumber($request).' — '.$request->title,
            'الزبون: '.$client,
            'الباقة: '.($package ?: 'طلب يدوي').$period,
            'المبلغ: '.Money::format($amount),
            'الدفعة الأولى: '.Money::format($first).($request->requires_full_payment ? ' (كامل المبلغ)' : ' (50%)'),
        ];
        if (filled($notes)) {
            $lines[] = 'الملاحظة: '.$notes;
        }
        if (! $hasPdf) {
            $lines[] = 'بلا ملف PDF لأن أودو لم يرد.';
        }

        return implode("\n", $lines);
    }

    private function key(string $token): string
    {
        return 'quotation-preview:'.$token;
    }
}
