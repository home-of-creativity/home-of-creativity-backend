<?php

namespace App\Support;

use App\Enums\PaymentMethod;
use App\Models\ServiceRequest;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Stationery for the file the client receives when Odoo does not return a PDF.
 * The letterhead stays fixed; the request data is printed in the body.
 */
class CorrespondenceDocument
{
    /**
     * @param  list<array{title: string, amount: float|int|string, units?: float|int|string|null, notes?: string|null}>|null  $lines
     */
    public function quotation(
        ServiceRequest $request,
        float $amount,
        ?string $notes,
        ?array $lines,
        string $relativePath,
    ): string {
        $request->loadMissing('client');
        $rows = $this->quotationRows($request, $amount, $lines);

        return $this->put($relativePath, [
            'kicker' => 'عرض سعر',
            'number' => (string) $request->number,
            'date' => $this->today(),
            'clientName' => $this->text($request->client?->name),
            'company' => $this->text($request->client?->company_name),
            'phone' => $this->text($request->client?->phone),
            'email' => $this->text($request->client?->email),
            'subject' => $this->text($request->title),
            'rows' => $rows,
            'notes' => filled($notes) ? trim((string) $notes) : null,
            'total' => Money::format($amount),
            'aside' => null,
        ]);
    }

    public function invoice(
        ServiceRequest $request,
        string $invoiceNumber,
        float $amount,
        string $kind,
        string $paymentMethod,
        string $relativePath,
    ): string {
        $request->loadMissing('client');
        $total = (float) ($request->amount_total ?? $request->quotation_amount ?? 0);
        $paid = (float) ($request->amount_paid ?? 0);
        $remaining = (float) ($request->amount_remaining ?? max($total - $paid, 0));
        $methodLabel = $paymentMethod === PaymentMethod::Receipt->value ? 'وصل بنكي' : 'نقداً';

        return $this->put($relativePath, [
            'kicker' => 'فاتورة',
            'number' => $invoiceNumber,
            'date' => $this->today(),
            'clientName' => $this->text($request->client?->name),
            'company' => $this->text($request->client?->company_name),
            'phone' => $this->text($request->client?->phone),
            'email' => $this->text($request->client?->email),
            'subject' => $this->text($request->title),
            'rows' => [
                ['label' => 'هذه الدفعة', 'value' => Money::format($amount)],
                ['label' => 'المدفوع', 'value' => Money::format($paid)],
                ['label' => 'المتبقي', 'value' => Money::format($remaining)],
            ],
            'notes' => filled($request->quotation_notes) ? trim((string) $request->quotation_notes) : null,
            'total' => Money::format($amount),
            'aside' => $this->invoiceKind($kind).' · '.$methodLabel,
        ]);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function put(string $relativePath, array $document): string
    {
        $binary = Pdf::loadView('correspondence.letter', $document)
            ->setPaper('a4')
            ->output();
        Storage::disk('local')->put($relativePath, $binary);

        return $relativePath;
    }

    /**
     * @param  list<array{title: string, amount: float|int|string, units?: float|int|string|null, notes?: string|null}>|null  $lines
     * @return list<array{label: string, value: string}>
     */
    private function quotationRows(ServiceRequest $request, float $amount, ?array $lines): array
    {
        if ($lines === null || $lines === []) {
            return [
                ['label' => $this->text($request->title), 'value' => Money::format($amount)],
            ];
        }

        return collect($lines)
            ->values()
            ->map(function (array $line): array {
                $units = (float) ($line['units'] ?? 1);
                $unitPrice = (float) $line['amount'];
                $label = (string) $line['title'];
                if (filled($line['notes'] ?? null)) {
                    $label .= ' — '.$line['notes'];
                }

                return [
                    'label' => $label,
                    'value' => $units.' × '.Money::format($unitPrice).' = '.Money::format($units * $unitPrice),
                ];
            })
            ->all();
    }

    private function invoiceKind(string $kind): string
    {
        return match ($kind) {
            'deposit' => 'دفعة أولى',
            'remaining' => 'المتبقي',
            'renewal' => 'تجديد',
            'received' => 'دفعة مستلمة',
            default => 'كامل المبلغ',
        };
    }

    private function today(): string
    {
        return Carbon::now('Asia/Damascus')->format('Y-m-d');
    }

    private function text(mixed $value): string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : '—';
    }
}
