<?php

namespace App\Actions;

use App\Enums\RequestStatus;
use App\Models\PaymentReminder;
use App\Models\ServiceRequest;
use App\Models\Subscription;
use App\Support\BillingPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RenewSubscription
{
    public function __construct(
        private SendQuotation $sendQuotation,
        private SchedulePaymentReminders $schedulePaymentReminders,
    ) {}

    public function handle(ServiceRequest $request): ServiceRequest
    {
        if (! $request->allows_renewal) {
            throw ValidationException::withMessages(['renewal' => 'This request does not allow renewal.']);
        }

        if ($request->status === RequestStatus::Cancelled) {
            throw ValidationException::withMessages(['renewal' => 'Cancelled requests cannot be renewed.']);
        }

        $period = $request->billing_period ?: 'monthly';
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

        $fresh = DB::transaction(function () use ($request, $period, $amount): ServiceRequest {
            $previousEnd = $request->subscription_ends_at && $request->subscription_ends_at->isFuture()
                ? $request->subscription_ends_at->copy()
                : now();
            $newEnd = BillingPeriod::addPeriod($previousEnd, $period);

            $request->forceFill([
                'subscription_starts_at' => $request->subscription_starts_at ?? now(),
                'subscription_ends_at' => $newEnd,
            ])->save();

            Subscription::query()->create([
                'request_id' => $request->id,
                'billing_period' => $period,
                'starts_at' => $previousEnd,
                'ends_at' => $newEnd,
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

        $this->sendQuotation->handle(
            $fresh,
            $amount,
            'تجديد اشتراك — '.BillingPeriod::labelAr($period),
            'client:renewal',
            null,
            null,
            true,
        );

        $this->schedulePaymentReminders->handle($fresh->fresh(['subscriptions']) ?? $fresh);

        return $fresh->fresh(['client', 'subscriptions', 'pricingPackage', 'quotations']) ?? $fresh;
    }
}
