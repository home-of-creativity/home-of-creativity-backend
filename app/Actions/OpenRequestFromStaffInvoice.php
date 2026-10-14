<?php

namespace App\Actions;

use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\RequestStatusHistory;
use App\Models\ServiceRequest;
use Illuminate\Support\Str;

class OpenRequestFromStaffInvoice
{
    public function __construct(
        private GenerateRequestNumber $numbers,
        private ProvisionSalesClickUpTask $salesTask,
        private EnsureRequestDriveFolder $drive,
    ) {}

    /**
     * @param  array<string, mixed>  $created
     * @param  list<array{title?: string|null, amount?: float|int|string|null, units?: float|int|string|null, discount?: float|int|string|null, display_type?: string|null}>  $lines
     */
    public function handle(
        Client $client,
        array $created,
        array $lines,
        ?string $reference,
        string $origin = 'invoice',
        ?int $orderId = null,
        ?float $orderTotal = null,
    ): ServiceRequest {
        $quotation = $origin === 'quotation';
        $title = $this->title($created, $lines, $reference, $quotation ? 'عرض سعر' : 'فاتورة');
        // A down payment invoice of an order: the request is for the whole order.
        $amount = $orderTotal !== null && $orderTotal > 0 ? $orderTotal : $this->amount($created, $lines);
        $odooId = (int) ($created['id'] ?? 0);
        $label = $quotation ? 'عرض سعر' : 'فاتورة';

        $request = $client->requests()->create([
            'number' => $this->numbers->handle(),
            'title' => $title,
            'description' => filled($reference)
                ? "{$label} من لوحة التحكم: {$reference}"
                : "{$label} من لوحة التحكم.",
            'status' => RequestStatus::Submitted,
            'source' => $client->requestSource(),
            'odoo_invoice_id' => ! $quotation && $odooId > 0 ? (string) $odooId : null,
            'odoo_quotation_id' => $quotation && $odooId > 0 ? (string) $odooId : ($orderId !== null && $orderId > 0 ? (string) $orderId : null),
            'amount_total' => $amount,
            'amount_remaining' => $amount,
        ]);

        RequestStatusHistory::query()->create([
            'request_id' => $request->id,
            'from_status' => null,
            'to_status' => RequestStatus::Submitted->value,
            'actor' => 'admin',
            'note' => $quotation ? 'Opened from a staff quotation.' : 'Opened from a staff invoice.',
        ]);

        $request = $this->salesTask->handle($request->fresh(['client']) ?? $request);

        return $this->drive->handleQuietly($request);
    }

    /**
     * @param  array<string, mixed>  $created
     * @param  list<array{title?: string|null, display_type?: string|null}>  $lines
     */
    private function title(array $created, array $lines, ?string $reference, string $fallback = 'فاتورة'): string
    {
        $titles = collect($lines)
            ->filter(fn (array $line): bool => ! in_array($line['display_type'] ?? null, ['line_section', 'line_note'], true))
            ->pluck('title')
            ->filter(fn (mixed $title): bool => is_string($title) && trim($title) !== '')
            ->take(3)
            ->implode('، ');

        $title = $titles !== ''
            ? $titles
            : (is_string($created['name'] ?? null) && $created['name'] !== '' ? (string) $created['name'] : (filled($reference) ? $reference : $fallback));

        return Str::limit($title, 180, '');
    }

    /**
     * @param  array<string, mixed>  $created
     * @param  list<array{amount?: float|int|string|null, units?: float|int|string|null, discount?: float|int|string|null, display_type?: string|null}>  $lines
     */
    private function amount(array $created, array $lines): float
    {
        $priced = array_values(array_filter(
            $lines,
            fn (array $line): bool => ! in_array($line['display_type'] ?? null, ['line_section', 'line_note'], true),
        ));
        // Odoo adds the taxes; its total is what the client pays.
        if (collect($priced)->contains(fn (array $line): bool => ! empty($line['tax_ids'])) && (float) ($created['amount_total'] ?? 0) > 0) {
            return (float) $created['amount_total'];
        }
        if ($priced !== []) {
            return (float) collect($priced)->sum(function (array $line): float {
                $discount = min(100, max(0, (float) ($line['discount'] ?? 0)));

                return (float) ($line['amount'] ?? 0) * (float) ($line['units'] ?? 1) * (1 - ($discount / 100));
            });
        }

        return max(0, (float) ($created['amount_total'] ?? 0));
    }
}
