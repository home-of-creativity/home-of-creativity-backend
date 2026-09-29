<?php

namespace App\Actions;

use App\Models\PaymentReminder;
use App\Models\ServiceRequest;

class DeclineRenewal
{
    public function handle(ServiceRequest $request): ServiceRequest
    {
        PaymentReminder::query()
            ->where('request_id', $request->id)
            ->where('kind', PaymentReminder::KIND_RENEWAL)
            ->whereNull('completed_at')
            ->update(['completed_at' => now()]);

        $latest = $request->subscriptions()->latest('id')->first();
        if ($latest) {
            $latest->forceFill([
                'renewal_declined' => true,
                'status' => $latest->status === 'pending_renewal' ? 'pending_renewal' : 'expiring',
            ])->save();
        } else {
            $request->subscriptions()->create([
                'billing_period' => $request->billing_period ?: 'monthly',
                'starts_at' => $request->subscription_starts_at ?? now(),
                'ends_at' => $request->subscription_ends_at ?? now(),
                'amount' => (float) ($request->amount_total ?? 0),
                'status' => 'expiring',
                'renewal_declined' => true,
            ]);
        }

        return $request->fresh(['subscriptions', 'paymentReminders']) ?? $request;
    }
}
