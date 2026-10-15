<?php

namespace App\Actions;

use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Services\OdooClient;
use App\Services\RequestStatusTransitionService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A client who answers a quotation on the Odoo portal (the link in the email) moves the
 * request the same way as the bot buttons: accepted in Odoo approves it, declined in Odoo
 * rejects it. Answers given on the bot already reach Odoo through those actions.
 *
 * A quotation deleted in Odoo takes its request with it. A request with money on it is
 * cancelled instead and keeps its record.
 */
class SyncOdooQuotations
{
    public function __construct(
        private OdooClient $odoo,
        private ApproveQuotation $approve,
        private RejectQuotation $reject,
        private RequestStatusTransitionService $transitions,
    ) {}

    /**
     * @return array{checked: int, approved: int, rejected: int, deleted: int, cancelled: int}
     */
    public function handle(): array
    {
        $result = ['checked' => 0, 'approved' => 0, 'rejected' => 0, 'deleted' => 0, 'cancelled' => 0];
        if (! $this->odoo->configured()) {
            return $result;
        }

        // Orders confirmed inside Odoo get their order number too (QN00041 → S00041).
        try {
            $this->odoo->renameConfirmedOrders();
        } catch (Throwable $exception) {
            Log::warning('Odoo order numbers were not updated.', ['error' => $exception->getMessage()]);
        }

        $this->followDeleted($result);

        $waiting = ServiceRequest::query()
            ->with('client')
            ->where('status', RequestStatus::QuotationSent)
            ->whereNotNull('odoo_quotation_id')
            ->latest('id')
            ->limit(200)
            ->get();
        if ($waiting->isEmpty()) {
            return $result;
        }

        $states = $this->odoo->saleOrderStates($waiting->pluck('odoo_quotation_id')->map(fn (mixed $id): int => (int) $id)->all());
        foreach ($waiting as $request) {
            $result['checked']++;
            $state = $states[(int) $request->odoo_quotation_id] ?? '';
            try {
                if ($state === 'sale') {
                    $this->approve->handle($request, null, 'client:odoo');
                    $result['approved']++;
                } elseif ($state === 'cancel') {
                    $this->reject->handle($request, 'رفض العميل العرض من رابط أودو.', null, 'client:odoo');
                    $result['rejected']++;
                }
            } catch (Throwable $exception) {
                Log::warning('Odoo quotation answer did not reach the request.', [
                    'request' => $request->number,
                    'state' => $state,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * @param  array{checked: int, approved: int, rejected: int, deleted: int, cancelled: int}  $result
     */
    private function followDeleted(array &$result): void
    {
        $linked = ServiceRequest::query()
            ->whereNotNull('odoo_quotation_id')
            ->where('odoo_quotation_id', '!=', '')
            ->pluck('odoo_quotation_id', 'id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0);
        if ($linked->isEmpty()) {
            return;
        }

        $ids = $linked->unique()->values()->all();
        $states = $this->odoo->saleOrderStates($ids);
        $missing = array_values(array_diff($ids, array_keys($states)));
        if ($missing === []) {
            return;
        }
        if ($states === [] && ! $this->odoo->hasAnyRecord('sale.order')) {
            return;
        }
        // Asked twice, so an order Odoo was still writing is not taken for deleted.
        $gone = array_values(array_diff($missing, array_keys($this->odoo->saleOrderStates($missing))));

        foreach ($linked->filter(fn (int $orderId): bool => in_array($orderId, $gone, true)) as $requestId => $orderId) {
            $request = ServiceRequest::query()->find($requestId);
            if ($request === null || (int) $request->odoo_quotation_id !== $orderId) {
                continue;
            }
            try {
                $this->followDeletedQuotation($request, $result);
            } catch (Throwable $exception) {
                Log::warning('A request stayed after its Odoo quotation was deleted.', [
                    'request' => $request->number,
                    'odoo_quotation_id' => $orderId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * A quotation deleted from the dashboard takes its requests with it now. A paid
     * request stays (cancelled when it still can be).
     *
     * @return array{deleted: list<string>, kept: list<string>}
     */
    public function forgetQuotation(int $orderId): array
    {
        $result = ['checked' => 0, 'approved' => 0, 'rejected' => 0, 'deleted' => 0, 'cancelled' => 0];
        $numbers = ['deleted' => [], 'kept' => []];
        $linked = ServiceRequest::query()->where('odoo_quotation_id', (string) $orderId)->get();
        foreach ($linked as $request) {
            $before = $result['deleted'];
            $this->followDeletedQuotation($request, $result);
            $numbers[$result['deleted'] > $before ? 'deleted' : 'kept'][] = (string) $request->number;
        }

        return $numbers;
    }

    /**
     * @param  array{checked: int, approved: int, rejected: int, deleted: int, cancelled: int}  $result
     */
    private function followDeletedQuotation(ServiceRequest $request, array &$result): void
    {
        $paid = (float) ($request->amount_paid ?? 0) > 0.009
            || in_array($request->status, CancelClientRequest::PAID_STATUSES, true);
        if (! $paid) {
            Log::info('Request deleted with its Odoo quotation.', ['request' => $request->number, 'odoo_quotation_id' => $request->odoo_quotation_id]);
            $request->delete();
            $result['deleted']++;

            return;
        }

        $request->forceFill(['odoo_quotation_id' => null])->save();
        if ($request->status->canTransitionTo(RequestStatus::Cancelled)) {
            $this->transitions->transition($request, RequestStatus::Cancelled, 'odoo', 'The quotation was deleted in Odoo.');
            $result['cancelled']++;
        }
    }
}
