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
    public function handle(Client $client, array $created, array $lines, ?string $reference): ServiceRequest
    {
        $title = $this->title($created, $lines, $reference);
        $amount = $this->amount($created, $lines);
        $invoiceId = (int) ($created['id'] ?? 0);

        $request = $client->requests()->create([
            'number' => $this->numbers->handle(),
            'title' => $title,
            'description' => filled($reference)
                ? 'فاتورة من لوحة التحكم: '.$reference
                : 'فاتورة من لوحة التحكم.',
            'status' => RequestStatus::Submitted,
            'source' => $client->requestSource(),
            'odoo_invoice_id' => $invoiceId > 0 ? (string) $invoiceId : null,
            'amount_total' => $amount,
            'amount_remaining' => $amount,
        ]);

        RequestStatusHistory::query()->create([
            'request_id' => $request->id,
            'from_status' => null,
            'to_status' => RequestStatus::Submitted->value,
            'actor' => 'admin',
            'note' => 'Opened from a staff invoice.',
        ]);

        $request = $this->salesTask->handle($request->fresh(['client']) ?? $request);

        return $this->drive->handleQuietly($request);
    }

    /**
     * @param  array<string, mixed>  $created
     * @param  list<array{title?: string|null, display_type?: string|null}>  $lines
     */
    private function title(array $created, array $lines, ?string $reference): string
    {
        $titles = collect($lines)
            ->filter(fn (array $line): bool => ! in_array($line['display_type'] ?? null, ['line_section', 'line_note'], true))
            ->pluck('title')
            ->filter(fn (mixed $title): bool => is_string($title) && trim($title) !== '')
            ->take(3)
            ->implode('، ');

        $title = $titles !== ''
            ? $titles
            : (is_string($created['name'] ?? null) && $created['name'] !== '' ? (string) $created['name'] : (filled($reference) ? $reference : 'فاتورة'));

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
        if ($priced !== []) {
            return (float) collect($priced)->sum(function (array $line): float {
                $discount = min(100, max(0, (float) ($line['discount'] ?? 0)));

                return (float) ($line['amount'] ?? 0) * (float) ($line['units'] ?? 1) * (1 - ($discount / 100));
            });
        }

        return max(0, (float) ($created['amount_total'] ?? 0));
    }
}
