<?php

namespace App\Actions;

use App\Models\PaymentReminder;
use App\Models\ServiceRequest;
use App\Models\Subscription;
use App\Support\BillingPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RenewSubscription
{
    public function __construct(
        private IssueInvoice $issueInvoice,
        private NotifyClientChannels $notifyClientChannels,
    ) {}

    public function handle(ServiceRequest $request): ServiceRequest
    {
        if (! $request->canRenew()) {
            throw ValidationException::withMessages(['renewal' => 'Renewal is only available near the end of the subscription.']);
        }

        $pending = $request->subscriptions()->where('status', 'pending_renewal')->latest('id')->first();
        if ($pending) {
            $this->deliverInvoice($request, (float) $pending->amount);

            return $request->fresh(['client', 'subscriptions', 'pricingPackage', 'invoices']) ?? $request;
        }

        $period = $request->billing_period ?: 'monthly';
        if (! BillingPeriod::isSubscription($period)) {
            throw ValidationException::withMessages(['renewal' => 'This request is not a subscription.']);
        }

        $amount = (float) ($request->quotation_amount ?? $request->amount_total ?? 0);
        if ($amount <= 0 && $request->pricingPackage) {
            $prices = $request->pricingPackage->prices ?? [];
            if (isset($prices[$period]) && is_numeric($prices[$period])) {
                $amount = (float) $prices[$period];
            }
        }
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'No renewal amount available.']);
        }

        $proposedStart = $request->subscription_ends_at && $request->subscription_ends_at->isFuture()
            ? $request->subscription_ends_at->copy()
            : now();
        $proposedEnd = BillingPeriod::addPeriod($proposedStart, $period);

        $fresh = DB::transaction(function () use ($request, $period, $amount, $proposedStart, $proposedEnd): ServiceRequest {
            Subscription::query()->create([
                'request_id' => $request->id,
                'billing_period' => $period,
                'starts_at' => $proposedStart,
                'ends_at' => $proposedEnd,
                'amount' => $amount,
                'amount_paid' => 0,
                'amount_remaining' => $amount,
                'payment_plan' => $request->payment_plan,
                'status' => 'pending_renewal',
                'renewal_declined' => false,
            ]);

            PaymentReminder::query()
                ->where('request_id', $request->id)
                ->where('kind', PaymentReminder::KIND_RENEWAL)
                ->whereNull('completed_at')
                ->update(['completed_at' => now()]);

            return $request->fresh(['client', 'pricingPackage', 'subscriptions']) ?? $request;
        });

        $this->deliverInvoice($fresh, $amount);

        return $fresh->fresh(['client', 'subscriptions', 'pricingPackage', 'invoices']) ?? $fresh;
    }

    private function deliverInvoice(ServiceRequest $request, float $amount): void
    {
        $this->issueInvoice->handle($request, $amount, 'renewal', false);
        $this->notifyClientChannels->send($request, 'تجديد الاشتراك. أُرسل ملف الفاتورة بالمبلغ المتفق عليه.');
    }
}
