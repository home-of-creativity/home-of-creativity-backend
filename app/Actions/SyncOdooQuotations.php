<?php

namespace App\Actions;

use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Services\OdooClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A client who answers a quotation on the Odoo portal (the link in the email) moves the
 * request the same way as the bot buttons: accepted in Odoo approves it, declined in Odoo
 * rejects it. Answers given on the bot already reach Odoo through those actions.
 */
class SyncOdooQuotations
{
    public function __construct(
        private OdooClient $odoo,
        private ApproveQuotation $approve,
        private RejectQuotation $reject,
    ) {}

    /**
     * @return array{checked: int, approved: int, rejected: int}
     */
    public function handle(): array
    {
        $result = ['checked' => 0, 'approved' => 0, 'rejected' => 0];
        if (! $this->odoo->configured()) {
            return $result;
        }

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
}
