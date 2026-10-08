<?php

namespace App\Actions;

use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\OpsExpense;
use App\Models\ServiceRequest;
use App\Services\OdooClient;
use App\Support\ResolveServiceRequest;
use App\Support\StatusLabel;
use Throwable;

class AdminBotDesk
{
    public function __construct(private OdooClient $odoo) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $finance = $this->finance();

        return [
            'clients' => Client::query()->visibleOnDashboard()->count(),
            'open_requests' => ServiceRequest::query()
                ->whereNotIn('status', [RequestStatus::Completed, RequestStatus::Cancelled])
                ->count(),
            'awaiting_payment' => ServiceRequest::query()->where('status', RequestStatus::AwaitingPayment)->count(),
            'in_progress' => ServiceRequest::query()->where('status', RequestStatus::InProgress)->count(),
            'ready_for_review' => ServiceRequest::query()->where('status', RequestStatus::ReadyForReview)->count(),
            'revenue_paid' => $finance['revenue_paid'],
            'revenue_open' => $finance['revenue_open'],
            'expenses' => $finance['expenses'],
            'net' => $finance['net'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function clients(): array
    {
        return Client::query()
            ->visibleOnDashboard()
            ->with(['requests' => fn ($query) => $query->latest('id')->limit(3)])
            ->withCount([
                'requests as open_count' => fn ($query) => $query->whereNotIn('status', [
                    RequestStatus::Completed,
                    RequestStatus::Cancelled,
                ]),
            ])
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (Client $client): array => $this->clientCard($client))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function client(int $id): array
    {
        $client = Client::query()
            ->visibleOnDashboard()
            ->with(['requests' => fn ($query) => $query->latest('id')->limit(12)])
            ->findOrFail($id);

        return $this->clientCard($client, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function operations(): array
    {
        return ServiceRequest::query()
            ->with(['client', 'pricingPackage'])
            ->whereNotIn('status', [RequestStatus::Cancelled])
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (ServiceRequest $request): array => $this->operationCard($request))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function operation(ServiceRequest $request): array
    {
        $request->loadMissing(['client', 'pricingPackage']);

        return $this->operationCard($request);
    }

    /**
     * @param  array{from?: string|null, to?: string|null, client?: string|null, invoice_state?: string|null, category?: string|null, q?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function finance(array $filters = []): array
    {
        $invoiceRows = $this->financeInvoices();
        $expenseRows = OpsExpense::query()
            ->latest('id')
            ->limit(200)
            ->get()
            ->map(fn (OpsExpense $expense): array => [
                'id' => $expense->id,
                'amount' => (float) $expense->amount,
                'category' => $expense->category,
                'note' => $expense->note,
                'spent_at' => $expense->spent_at?->toDateString(),
            ])
            ->all();

        $clients = array_values(array_unique(array_filter(array_map(
            fn (array $row): string => trim((string) ($row['partner_name'] ?? '')),
            $invoiceRows,
        ))));
        sort($clients);

        $filteredInvoices = array_values(array_filter(
            $invoiceRows,
            fn (array $row): bool => $this->invoiceMatches($row, $filters),
        ));
        $filteredExpenses = array_values(array_filter(
            $expenseRows,
            fn (array $row): bool => $this->expenseMatches($row, $filters),
        ));

        $revenuePaid = round(array_sum(array_map(
            fn (array $row): float => (float) $row['collected'],
            $filteredInvoices,
        )), 2);
        if ($revenuePaid <= 0 && $invoiceRows === [] && $this->filtersAreEmpty($filters)) {
            $revenuePaid = round((float) ServiceRequest::query()->sum('amount_paid'), 2);
        }

        $openQuery = ServiceRequest::query()->whereNotIn('status', [RequestStatus::Cancelled]);
        $client = trim((string) ($filters['client'] ?? ''));
        if ($client !== '') {
            $openQuery->whereHas('client', function ($query) use ($client): void {
                $like = '%'.addcslashes($client, '%_\\').'%';
                $query->where('name', 'like', $like)->orWhere('company_name', 'like', $like);
            });
        }
        $revenueOpen = round((float) $openQuery->sum('amount_remaining'), 2);
        $expenses = round(array_sum(array_column($filteredExpenses, 'amount')), 2);

        return [
            'revenue_paid' => $revenuePaid,
            'revenue_open' => $revenueOpen,
            'expenses' => $expenses,
            'net' => round($revenuePaid - $expenses, 2),
            'invoices' => $filteredInvoices,
            'clients' => $clients,
            'expense_rows' => $filteredExpenses,
            'categories' => OpsExpense::categories(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function recordExpense(float $amount, string $category, ?string $note, ?string $telegramId): array
    {
        $allowed = OpsExpense::categories();
        if (! in_array($category, $allowed, true)) {
            $category = 'أخرى';
        }

        $expense = OpsExpense::query()->create([
            'amount' => round($amount, 2),
            'category' => $category,
            'note' => $note,
            'created_by_telegram_id' => $telegramId,
            'spent_at' => now(),
        ]);

        return [
            'id' => $expense->id,
            'amount' => (float) $expense->amount,
            'category' => $expense->category,
            'finance' => $this->finance(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function clientCard(Client $client, bool $withRequests = false): array
    {
        $latest = $client->requests->first();

        $card = [
            'id' => $client->id,
            'name' => $client->name,
            'company_name' => $client->company_name,
            'phone' => $client->phone,
            'telegram_url' => $client->telegramPrivateUrl(),
            'latest_status' => $latest?->status?->value,
            'latest_status_label' => $latest ? StatusLabel::requestAr($latest->status->value) : null,
            'latest_title' => $latest?->title,
            'open_count' => (int) ($client->open_count ?? $client->requests
                ->filter(fn (ServiceRequest $request): bool => ! in_array($request->status, [RequestStatus::Completed, RequestStatus::Cancelled], true))
                ->count()),
        ];

        if ($withRequests) {
            $card['requests'] = $client->requests->map(fn (ServiceRequest $request): array => [
                'number' => $request->number,
                'display_number' => ResolveServiceRequest::displayNumber($request),
                'title' => $request->title,
                'status' => $request->status->value,
                'status_label' => StatusLabel::requestAr($request->status->value),
                'amount_paid' => (float) ($request->amount_paid ?? 0),
                'amount_remaining' => (float) ($request->amount_remaining ?? 0),
            ])->values()->all();
        }

        return $card;
    }

    /**
     * @return array<string, mixed>
     */
    private function operationCard(ServiceRequest $request): array
    {
        $operations = data_get($request->work_plan, 'operations');
        $operations = is_array($operations) ? $operations : [];

        return [
            'number' => $request->number,
            'display_number' => ResolveServiceRequest::displayNumber($request),
            'title' => $request->title,
            'status' => $request->status->value,
            'status_label' => StatusLabel::requestAr($request->status->value),
            'client_name' => $request->client?->name,
            'company_name' => $request->client?->company_name,
            'package_name' => $request->pricingPackage?->name_ar ?: $request->pricingPackage?->name_en,
            'source' => data_get($request->work_plan, 'source'),
            'operations' => array_map(fn (array $operation): array => [
                'department' => $operation['department'] ?? null,
                'employee_name' => $operation['employee_name'] ?? null,
                'priority_label' => $operation['priority_label'] ?? null,
                'hours' => $operation['hours'] ?? null,
                'brief' => $operation['brief'] ?? null,
            ], $operations),
        ];
    }

    /**
     * Received money follows the live invoice state. A cancelled Odoo invoice
     * contributes nothing, even when it was paid before.
     *
     * @return list<array<string, mixed>>
     */
    private function financeInvoices(): array
    {
        $local = Invoice::query()
            ->with('request.client:id,name,company_name')
            ->where('kind', 'received')
            ->latest('id')
            ->limit(200)
            ->get();

        $live = $this->liveOdooInvoices();
        $liveById = [];
        foreach ($live as $row) {
            $liveById[(string) $row['id']] = $row;
        }

        $cancelIds = [];
        $paidIds = [];
        foreach ($live as $row) {
            $id = (string) $row['id'];
            if (($row['state'] ?? '') === 'cancel') {
                $cancelIds[] = $id;
            } elseif (in_array((string) ($row['payment_state'] ?? ''), ['paid', 'in_payment'], true)) {
                $paidIds[] = $id;
            }
        }
        if ($cancelIds !== []) {
            Invoice::query()->whereIn('odoo_invoice_id', $cancelIds)->update(['status' => 'cancelled']);
        }
        if ($paidIds !== []) {
            Invoice::query()->whereIn('odoo_invoice_id', $paidIds)->where('status', '!=', 'cancelled')->update(['status' => 'paid']);
        }

        $rows = [];
        $linked = [];
        foreach ($local as $invoice) {
            $odooId = filled($invoice->odoo_invoice_id) ? (string) $invoice->odoo_invoice_id : '';
            $remote = $odooId !== '' ? ($liveById[$odooId] ?? null) : null;
            if ($odooId !== '') {
                $linked[$odooId] = true;
            }
            if ($remote !== null) {
                $rows[] = $this->financeInvoiceRow($remote, $invoice);
                continue;
            }
            if (in_array((string) $invoice->status, ['cancelled', 'void'], true)) {
                $rows[] = $this->localInvoiceRow($invoice, 'cancelled', 0.0);
                continue;
            }
            $rows[] = $this->localInvoiceRow($invoice, 'paid', (float) $invoice->amount);
        }

        foreach ($live as $row) {
            if (isset($linked[(string) $row['id']])) {
                continue;
            }
            $rows[] = $this->financeInvoiceRow($row, null);
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function liveOdooInvoices(): array
    {
        if (! $this->odoo->configured()) {
            return [];
        }

        try {
            return $this->odoo->listInvoices(200);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function financeInvoiceRow(array $remote, ?Invoice $invoice): array
    {
        $state = (string) ($remote['state'] ?? '');
        $payment = (string) ($remote['payment_state'] ?? '');
        $collected = 0.0;
        if ($state !== 'cancel' && in_array($payment, ['paid', 'in_payment', 'partial'], true)) {
            $collected = round(max((float) $remote['amount_total'] - (float) $remote['amount_residual'], 0), 2);
        }
        $bucket = match (true) {
            $state === 'cancel' => 'cancelled',
            in_array($payment, ['paid', 'in_payment'], true) => 'paid',
            $payment === 'partial' => 'partial',
            default => 'open',
        };
        $partner = (string) ($remote['partner_name'] ?? '');
        if ($partner === '' && $invoice?->request?->client) {
            $partner = (string) ($invoice->request->client->company_name ?: $invoice->request->client->name);
        }

        return [
            'id' => $invoice?->id ?? (int) $remote['id'],
            'name' => (string) ($remote['name'] ?? $invoice?->invoice_number ?? ''),
            'amount' => (float) ($remote['amount_total'] ?? $invoice?->amount ?? 0),
            'collected' => $collected,
            'state' => $bucket,
            'payment_state' => $payment,
            'partner_name' => $partner !== '' ? $partner : null,
            'request_number' => $invoice?->request?->number ?? ($remote['ref'] ?? $remote['invoice_origin'] ?? null),
            'title' => $invoice?->request?->title,
            'issued_at' => $remote['invoice_date'] ?? $invoice?->issued_at?->toDateString(),
            'odoo_url' => $remote['odoo_url'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function localInvoiceRow(Invoice $invoice, string $bucket, float $collected): array
    {
        $client = $invoice->request?->client;
        $partner = $client ? (string) ($client->company_name ?: $client->name) : '';

        return [
            'id' => $invoice->id,
            'name' => (string) $invoice->invoice_number,
            'amount' => (float) $invoice->amount,
            'collected' => round($collected, 2),
            'state' => $bucket,
            'payment_state' => $bucket === 'paid' ? 'paid' : ($bucket === 'cancelled' ? 'cancel' : 'not_paid'),
            'partner_name' => $partner !== '' ? $partner : null,
            'request_number' => $invoice->request?->number,
            'title' => $invoice->request?->title,
            'issued_at' => $invoice->issued_at?->toDateString(),
            'odoo_url' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $filters
     */
    private function invoiceMatches(array $row, array $filters): bool
    {
        $state = (string) ($filters['invoice_state'] ?? '');
        if ($state !== '' && (string) $row['state'] !== $state) {
            return false;
        }
        $client = trim((string) ($filters['client'] ?? ''));
        if ($client !== '' && ! str_contains(mb_strtolower((string) ($row['partner_name'] ?? '')), mb_strtolower($client))) {
            return false;
        }
        if (! $this->dateMatches((string) ($row['issued_at'] ?? ''), $filters)) {
            return false;
        }
        $needle = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        if ($needle === '') {
            return true;
        }

        $haystack = mb_strtolower(implode(' ', array_filter([
            $row['name'] ?? null,
            $row['partner_name'] ?? null,
            $row['request_number'] ?? null,
            $row['title'] ?? null,
        ])));

        return str_contains($haystack, $needle);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $filters
     */
    private function expenseMatches(array $row, array $filters): bool
    {
        $category = (string) ($filters['category'] ?? '');
        if ($category !== '' && (string) $row['category'] !== $category) {
            return false;
        }
        if (! $this->dateMatches((string) ($row['spent_at'] ?? ''), $filters)) {
            return false;
        }
        $needle = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        if ($needle === '') {
            return true;
        }

        return str_contains(mb_strtolower((string) ($row['note'] ?? '').' '.(string) $row['category']), $needle);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function dateMatches(string $value, array $filters): bool
    {
        $from = (string) ($filters['from'] ?? '');
        $to = (string) ($filters['to'] ?? '');
        if ($from === '' && $to === '') {
            return true;
        }
        $day = substr($value, 0, 10);
        if ($day === '') {
            return false;
        }
        if ($from !== '' && $day < $from) {
            return false;
        }
        if ($to !== '' && $day > $to) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filtersAreEmpty(array $filters): bool
    {
        foreach (['from', 'to', 'client', 'invoice_state', 'category', 'q'] as $key) {
            if (trim((string) ($filters[$key] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }
}
