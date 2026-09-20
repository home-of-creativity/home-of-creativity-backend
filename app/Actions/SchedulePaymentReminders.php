<?php

namespace App\Actions;

use App\Models\PaymentReminder;
use App\Models\ServiceRequest;
use App\Services\GoogleCalendarClient;
use App\Support\BillingPeriod;
use Illuminate\Support\Facades\Log;

class SchedulePaymentReminders
{
    public function __construct(private GoogleCalendarClient $calendar) {}

    public function handle(ServiceRequest $request): void
    {
        $request->loadMissing('subscriptions');
        $starts = $request->subscription_starts_at;
        $ends = $request->subscription_ends_at;
        if ($starts === null || $ends === null) {
            return;
        }

        $duration = max($starts->diffInSeconds($ends), 1);

        if ($request->needsPaymentCollection() && $request->hasRemainingBalance() && $request->payment_plan === 'partial') {
            $this->ensureReminder(
                $request,
                PaymentReminder::KIND_REMAINING,
                $starts->copy()->addSeconds((int) floor($duration / 2)),
                'تذكير المتبقي — '.$request->number,
            );
        }

        if ($request->allows_renewal && BillingPeriod::isSubscription((string) $request->billing_period)) {
            $this->ensureReminder(
                $request,
                PaymentReminder::KIND_RENEWAL,
                $starts->copy()->addSeconds((int) floor($duration * 0.75)),
                'تجديد الاشتراك — '.$request->number,
            );
        }
    }

    private function ensureReminder(ServiceRequest $request, string $kind, $dueAt, string $summary): void
    {
        $existing = PaymentReminder::query()
            ->where('request_id', $request->id)
            ->where('kind', $kind)
            ->whereNull('completed_at')
            ->latest('id')
            ->first();

        if ($existing) {
            return;
        }

        $eventId = null;
        try {
            $eventId = $this->calendar->createEvent(
                $summary,
                'طلب #'.$request->number.' — '.$request->title,
                $dueAt,
            );
        } catch (\Throwable $exception) {
            Log::warning('Google Calendar reminder event failed.', [
                'request' => $request->number,
                'kind' => $kind,
                'error' => $exception->getMessage(),
            ]);
        }

        $subscriptionId = $request->subscriptions()->latest('id')->value('id');

        PaymentReminder::query()->create([
            'request_id' => $request->id,
            'subscription_id' => $subscriptionId,
            'kind' => $kind,
            'due_at' => $dueAt,
            'google_event_id' => $eventId,
            'send_count' => 0,
        ]);
    }
}
