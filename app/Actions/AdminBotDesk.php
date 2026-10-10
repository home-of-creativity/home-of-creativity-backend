<?php

namespace App\Actions;

use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\OdooInvoice;
use App\Models\OpsExpense;
use App\Models\ServiceRequest;
use App\Services\OdooClient;
use App\Support\ResolveServiceRequest;
use App\Support\StatusLabel;
use Throwable;

class AdminBotDesk
{
    private const INVOICE_BUCKETS = ['paid', 'partial', 'open', 'overdue', 'draft', 'cancelled'];

    public function __construct(
        private OdooClient $odoo,
        private SyncOdooInvoices $syncOdooInvoices,
    ) {}

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
     * Received money follows the Odoo invoice state copied by `odoo:sync-invoices`.
     * A cancelled or draft invoice adds nothing. Filters: q, invoice_state (paid,
     * partial, open, overdue, draft, cancelled), client, currency, from, to (invoice
     * date), amount_min, amount_max, category (expenses), sort, dir, page, per_page.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function finance(array $filters = []): array
    {
        $this->refreshOdooInvoices();

        $all = $this->financeInvoices($filters);
        $expenseRows = OpsExpense::query()
            ->latest('id')
            ->limit(500)
            ->get()
            ->map(fn (OpsExpense $expense): array => [
                'id' => $expense->id,
                'amount' => (float) $expense->amount,
                'category' => $expense->category,
                'note' => $expense->note,
                'spent_at' => $expense->spent_at?->toDateString(),
            ])
            ->all();

        $stateCounts = array_fill_keys(self::INVOICE_BUCKETS, 0);
        foreach ($all as $row) {
            $stateCounts[$row['state']] = ($stateCounts[$row['state']] ?? 0) + 1;
        }
        $state = (string) ($filters['invoice_state'] ?? '');
        $filteredInvoices = $state === ''
            ? $all
            : array_values(array_filter($all, fn (array $row): bool => $row['state'] === $state));
        $filteredExpenses = array_values(array_filter(
            $expenseRows,
            fn (array $row): bool => $this->expenseMatches($row, $filters),
        ));

        $mirrorHasRows = OdooInvoice::query()->exists();
        $revenuePaid = round(array_sum(array_column($filteredInvoices, 'collected')), 2);
        $hasInvoices = $all !== [] || $mirrorHasRows || Invoice::query()->where('kind', 'received')->exists();
        if ($revenuePaid <= 0 && ! $hasInvoices && $this->filtersAreEmpty($filters)) {
            $revenuePaid = round((float) ServiceRequest::query()->sum('amount_paid'), 2);
        }

        $open = array_filter($filteredInvoices, fn (array $row): bool => in_array($row['state'], ['open', 'partial', 'overdue'], true));
        $revenueOpen = $mirrorHasRows
            ? round(array_sum(array_column($open, 'residual')), 2)
            : $this->localOpenRevenue($filters);
        $overdue = array_filter($filteredInvoices, fn (array $row): bool => $row['state'] === 'overdue');
        $expenses = round(array_sum(array_column($filteredExpenses, 'amount')), 2);

        $sorted = $this->sortInvoices($filteredInvoices, (string) ($filters['sort'] ?? 'date'), (string) ($filters['dir'] ?? 'desc'));
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 200);
        $total = count($sorted);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max((int) ($filters['page'] ?? 1), 1), $lastPage);
        $pageRows = array_slice($sorted, ($page - 1) * $perPage, $perPage);

        $clients = $this->financeClients();
        $currencies = OdooInvoice::query()->whereNotNull('currency')->distinct()->orderBy('currency')->pluck('currency')->all();
        $status = SyncOdooInvoices::status();

        return [
            'revenue_paid' => $revenuePaid,
            'revenue_open' => $revenueOpen,
            'revenue_overdue' => round(array_sum(array_column($overdue, 'residual')), 2),
            'invoiced' => round(array_sum(array_map(
                fn (array $row): float => in_array($row['state'], ['cancelled', 'draft'], true) ? 0.0 : (float) $row['amount'],
                $filteredInvoices,
            )), 2),
            'expenses' => $expenses,
            'net' => round($revenuePaid - $expenses, 2),
            'invoices' => $pageRows,
            'state_counts' => $stateCounts,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total === 0 ? null : (($page - 1) * $perPage) + 1,
                'to' => $total === 0 ? null : (($page - 1) * $perPage) + count($pageRows),
            ],
            'clients' => $clients,
            'currencies' => $currencies,
            'expense_rows' => $filteredExpenses,
            'categories' => OpsExpense::categories(),
            'sync' => [
                'configured' => $this->odoo->configured(),
                'synced_at' => $status['synced_at'],
                'error' => $status['error'],
            ],
        ];
    }

    /**
     * Pulls the Odoo invoices changed since the last sync now, instead of waiting for the scheduler.
     *
     * @return array{synced: bool, changed: int, removed: int}
     */
    public function syncOdooInvoices(): array
    {
        return $this->syncOdooInvoices->handle();
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

    private function refreshOdooInvoices(): void
    {
        try {
            $this->syncOdooInvoices->refreshIfStale(30);
        } catch (Throwable) {
            // The page still shows the last copied state; sync.error says why it is old.
        }
    }

    /**
     * Every invoice row that matches the filters except the state filter, so the
     * state chips can show their counts. Odoo invoices come from `odoo_invoices`.
     * A received payment that never reached Odoo is listed from the local table.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function financeInvoices(array $filters): array
    {
        $query = OdooInvoice::query()->with('request:id,number,title');
        $from = trim((string) ($filters['from'] ?? ''));
        $to = trim((string) ($filters['to'] ?? ''));
        if ($from !== '') {
            $query->whereDate('invoice_date', '>=', $from);
        }
        if ($to !== '') {
            $query->whereDate('invoice_date', '<=', $to);
        }
        $client = trim((string) ($filters['client'] ?? ''));
        if ($client !== '') {
            $query->where('partner_name', 'like', $this->like($client));
        }
        $currency = trim((string) ($filters['currency'] ?? ''));
        if ($currency !== '') {
            $query->where('currency', $currency);
        }
        if (is_numeric($filters['amount_min'] ?? null)) {
            $query->where('amount_total', '>=', (float) $filters['amount_min']);
        }
        if (is_numeric($filters['amount_max'] ?? null)) {
            $query->where('amount_total', '<=', (float) $filters['amount_max']);
        }
        $needle = trim((string) ($filters['q'] ?? ''));
        if ($needle !== '') {
            $like = $this->like($needle);
            $query->where(function ($inner) use ($like): void {
                $inner->where('name', 'like', $like)
                    ->orWhere('partner_name', 'like', $like)
                    ->orWhere('ref', 'like', $like)
                    ->orWhere('invoice_origin', 'like', $like)
                    ->orWhereHas('request', fn ($request) => $request->where('number', 'like', $like)->orWhere('title', 'like', $like));
            });
        }

        $rows = $query->orderByDesc('invoice_date')->orderByDesc('odoo_id')->limit(5000)->get()
            ->map(fn (OdooInvoice $invoice): array => $this->odooInvoiceRow($invoice))
            ->all();

        $mirrored = OdooInvoice::query()->pluck('odoo_id')->map(fn ($id): string => (string) $id)->flip()->all();
        $local = Invoice::query()
            ->with('request.client:id,name,company_name')
            ->where('kind', 'received')
            ->latest('id')
            ->limit(500)
            ->get()
            ->filter(fn (Invoice $invoice): bool => ! filled($invoice->odoo_invoice_id) || ! isset($mirrored[(string) $invoice->odoo_invoice_id]))
            ->map(fn (Invoice $invoice): array => $this->localInvoiceRow($invoice))
            ->filter(fn (array $row): bool => $this->localInvoiceMatches($row, $filters))
            ->values()
            ->all();

        return array_values(array_merge($rows, $local));
    }

    /**
     * Every partner name on an Odoo invoice or a local received payment, for the client filter.
     *
     * @return list<string>
     */
    private function financeClients(): array
    {
        $names = OdooInvoice::query()->whereNotNull('partner_name')->distinct()->pluck('partner_name')->all();
        Invoice::query()
            ->with('request.client:id,name,company_name')
            ->where('kind', 'received')
            ->latest('id')
            ->limit(500)
            ->get()
            ->each(function (Invoice $invoice) use (&$names): void {
                $client = $invoice->request?->client;
                if ($client !== null) {
                    $names[] = (string) ($client->company_name ?: $client->name);
                }
            });
        $names = array_values(array_unique(array_filter(array_map(fn ($name): string => trim((string) $name), $names))));
        sort($names);

        return $names;
    }

    private function like(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
    }

    /**
     * @return array<string, mixed>
     */
    private function odooInvoiceRow(OdooInvoice $invoice): array
    {
        $bucket = $invoice->bucket();

        return [
            'id' => (int) $invoice->odoo_id,
            'source' => 'odoo',
            'name' => (string) $invoice->name,
            'amount' => (float) $invoice->amount_total,
            'collected' => $invoice->collected(),
            'residual' => in_array($bucket, ['cancelled', 'draft', 'paid'], true) ? 0.0 : round((float) $invoice->amount_residual, 2),
            'currency' => $invoice->currency,
            'state' => $bucket,
            'payment_state' => $invoice->payment_state,
            'odoo_state' => $invoice->state,
            'partner_name' => $invoice->partner_name,
            'request_id' => $invoice->request?->id,
            'request_number' => $invoice->request?->number ?? ($invoice->ref ?: $invoice->invoice_origin),
            'title' => $invoice->request?->title,
            'issued_at' => $invoice->invoice_date?->toDateString(),
            'due_at' => $invoice->invoice_date_due?->toDateString(),
            'odoo_url' => $this->odoo->recordUrl('account.move', (int) $invoice->odoo_id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function localInvoiceRow(Invoice $invoice): array
    {
        $client = $invoice->request?->client;
        $partner = $client ? (string) ($client->company_name ?: $client->name) : '';
        $cancelled = in_array((string) $invoice->status, ['cancelled', 'void'], true);

        return [
            'id' => $invoice->id,
            'source' => 'local',
            'name' => (string) $invoice->invoice_number,
            'amount' => (float) $invoice->amount,
            'collected' => $cancelled ? 0.0 : round((float) $invoice->amount, 2),
            'residual' => 0.0,
            'currency' => 'USD',
            'state' => $cancelled ? 'cancelled' : 'paid',
            'payment_state' => $cancelled ? 'cancel' : 'paid',
            'odoo_state' => null,
            'partner_name' => $partner !== '' ? $partner : null,
            'request_id' => $invoice->request?->id,
            'request_number' => $invoice->request?->number,
            'title' => $invoice->request?->title,
            'issued_at' => $invoice->issued_at?->toDateString(),
            'due_at' => null,
            'odoo_url' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $filters
     */
    private function localInvoiceMatches(array $row, array $filters): bool
    {
        $client = trim((string) ($filters['client'] ?? ''));
        if ($client !== '' && ! str_contains(mb_strtolower((string) ($row['partner_name'] ?? '')), mb_strtolower($client))) {
            return false;
        }
        $currency = trim((string) ($filters['currency'] ?? ''));
        if ($currency !== '' && $currency !== $row['currency']) {
            return false;
        }
        if (is_numeric($filters['amount_min'] ?? null) && (float) $row['amount'] < (float) $filters['amount_min']) {
            return false;
        }
        if (is_numeric($filters['amount_max'] ?? null) && (float) $row['amount'] > (float) $filters['amount_max']) {
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
     * Before the first Odoo copy, the open amount is what the requests still owe.
     *
     * @param  array<string, mixed>  $filters
     */
    private function localOpenRevenue(array $filters): float
    {
        $query = ServiceRequest::query()->whereNotIn('status', [RequestStatus::Cancelled]);
        $client = trim((string) ($filters['client'] ?? ''));
        if ($client !== '') {
            $like = $this->like($client);
            $query->whereHas('client', fn ($inner) => $inner->where('name', 'like', $like)->orWhere('company_name', 'like', $like));
        }

        return round((float) $query->sum('amount_remaining'), 2);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortInvoices(array $rows, string $sort, string $dir): array
    {
        $key = match ($sort) {
            'amount' => 'amount',
            'residual' => 'residual',
            'due' => 'due_at',
            'client' => 'partner_name',
            default => 'issued_at',
        };
        $sign = $dir === 'asc' ? 1 : -1;
        usort($rows, function (array $a, array $b) use ($key, $sign): int {
            $left = $a[$key] ?? null;
            $right = $b[$key] ?? null;
            if ($left === $right) {
                return $sign * ((int) $a['id'] <=> (int) $b['id']);
            }
            if ($left === null) {
                return 1;
            }
            if ($right === null) {
                return -1;
            }

            return $sign * (is_numeric($left) && is_numeric($right) ? ((float) $left <=> (float) $right) : strcmp((string) $left, (string) $right));
        });

        return $rows;
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
        foreach (['from', 'to', 'client', 'invoice_state', 'category', 'q', 'currency', 'amount_min', 'amount_max'] as $key) {
            if (trim((string) ($filters[$key] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }
}
