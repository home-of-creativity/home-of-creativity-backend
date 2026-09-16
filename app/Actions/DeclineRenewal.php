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

        $request->subscriptions()->latest('id')->first()?->forceFill([
            'renewal_declined' => true,
            'status' => 'expiring',
        ])->save();

        return $request->fresh(['subscriptions', 'paymentReminders']) ?? $request;
    }
}
