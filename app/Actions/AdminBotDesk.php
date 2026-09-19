<?php

namespace App\Actions;

use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\OpsExpense;
use App\Models\ServiceRequest;
use App\Support\ResolveServiceRequest;
use App\Support\StatusLabel;

class AdminBotDesk
{
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
     * @return array<string, mixed>
     */
    public function finance(): array
    {
        $revenuePaid = round((float) Invoice::query()->where('kind', 'received')->sum('amount'), 2);
        if ($revenuePaid <= 0) {
            $revenuePaid = round((float) ServiceRequest::query()->sum('amount_paid'), 2);
        }
        $revenueOpen = round((float) ServiceRequest::query()
            ->whereNotIn('status', [RequestStatus::Cancelled])
            ->sum('amount_remaining'), 2);
        $expenses = round((float) OpsExpense::query()->sum('amount'), 2);

        return [
            'revenue_paid' => $revenuePaid,
            'revenue_open' => $revenueOpen,
            'expenses' => $expenses,
            'net' => round($revenuePaid - $expenses, 2),
            'invoices' => Invoice::query()
                ->with('request:id,number,title')
                ->where('kind', 'received')
                ->latest('id')
                ->limit(8)
                ->get()
                ->map(fn (Invoice $invoice): array => [
                    'id' => $invoice->id,
                    'amount' => (float) $invoice->amount,
                    'request_number' => $invoice->request?->number,
                    'title' => $invoice->request?->title,
                    'issued_at' => $invoice->issued_at?->toDateString(),
                ])
                ->all(),
            'expense_rows' => OpsExpense::query()
                ->latest('id')
                ->limit(8)
                ->get()
                ->map(fn (OpsExpense $expense): array => [
                    'id' => $expense->id,
                    'amount' => (float) $expense->amount,
                    'category' => $expense->category,
                    'note' => $expense->note,
                    'spent_at' => $expense->spent_at?->toDateString(),
                ])
                ->all(),
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
}
