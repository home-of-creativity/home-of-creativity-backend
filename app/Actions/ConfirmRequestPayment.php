<?php

namespace App\Actions;

use App\Enums\GeminiStatus;
use App\Enums\PaymentMethod;
use App\Enums\RequestStatus;
use App\Jobs\ClassifyWithGeminiJob;
use App\Models\PaymentReminder;
use App\Models\ServiceRequest;
use App\Services\OdooClient;
use App\Services\RequestStatusTransitionService;
use App\Services\TelegramNotifier;
use App\Support\BillingPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ConfirmRequestPayment
{
    public function __construct(
        private RequestStatusTransitionService $transitions,
        private TelegramNotifier $telegram,
        private OdooClient $odoo,
        private EnsureRequestDriveFolder $ensureRequestDriveFolder,
        private SchedulePaymentReminders $schedulePaymentReminders,
        private IssueInvoice $issueInvoice,
    ) {}

    public function handle(ServiceRequest $request, PaymentMethod $method): ServiceRequest
    {
        $isFirst = $request->paid_at === null;
        $remainingOnly = ! $isFirst && $request->hasRemainingBalance();

        if ($isFirst && $request->status !== RequestStatus::AwaitingPayment) {
            throw ValidationException::withMessages([
                'status' => 'Payment can only be confirmed while awaiting payment.',
            ]);
        }

        if (! $isFirst && ! $remainingOnly) {
            throw ValidationException::withMessages([
                'status' => 'No remaining balance to confirm.',
            ]);
        }

        if ($isFirst && ($request->gemini_status === GeminiStatus::Pending || $request->gemini_status === GeminiStatus::Processing)) {
            throw ValidationException::withMessages([
                'gemini' => 'Gemini classification is already in progress.',
            ]);
        }

        $updated = DB::transaction(function () use ($request, $method, $isFirst): ServiceRequest {
            $total = (float) ($request->amount_total ?? $request->quotation_amount ?? 0);
            if ($total <= 0) {
                $total = (float) ($request->quotation_amount ?? 0);
            }

            $paid = (float) ($request->amount_paid ?? 0);
            $due = $request->requires_full_payment || $paid > 0.009
                ? max($total - $paid, 0)
                : round($total * 0.5, 2);

            $newPaid = round($paid + $due, 2);
            $newRemaining = max(round($total - $newPaid, 2), 0);

            $request->forceFill([
                'amount_total' => $total,
                'amount_paid' => $newPaid,
                'amount_remaining' => $newRemaining,
                'paid_at' => $request->paid_at ?? now(),
                'payment_method' => $method,
                'receipt_reupload_required' => false,
                'receipt_reupload_reason' => null,
            ])->save();

            if ($isFirst && BillingPeriod::isSubscription((string) $request->billing_period)) {
                $starts = now();
                $ends = BillingPeriod::addPeriod($starts, (string) $request->billing_period);
                $request->forceFill([
                    'subscription_starts_at' => $starts,
                    'subscription_ends_at' => $ends,
                ])->save();

                $request->subscriptions()->create([
                    'billing_period' => $request->billing_period,
                    'starts_at' => $starts,
                    'ends_at' => $ends,
                    'amount' => $total,
                    'amount_paid' => $newPaid,
                    'amount_remaining' => $newRemaining,
                    'payment_plan' => $request->payment_plan,
                    'status' => 'active',
                ]);
            }

            if ($newRemaining <= 0.009) {
                PaymentReminder::query()
                    ->where('request_id', $request->id)
                    ->where('kind', PaymentReminder::KIND_REMAINING)
                    ->whereNull('completed_at')
                    ->update(['completed_at' => now()]);
            }

            return $request->fresh(['client', 'files', 'pricingPackage', 'subscriptions']) ?? $request;
        });

        if ($updated->isFullyPaid()) {
            $this->markWon($updated);
        }

        $this->notifyClientSuccess($updated);

        if ($isFirst) {
            $this->ensureRequestDriveFolder->handle($updated);
            $this->schedulePaymentReminders->handle($updated->fresh() ?? $updated);

            if ($updated->hasRemainingBalance()) {
                try {
                    $this->issueInvoice->handle($updated, (float) $updated->amount_remaining, 'remaining', false);
                } catch (\Throwable) {
                    // Continue locally if Odoo invoice fails.
                }
            }

            if ($updated->gemini_status !== GeminiStatus::Success) {
                $updated->forceFill([
                    'gemini_status' => GeminiStatus::Pending,
                    'gemini_error' => null,
                ])->save();
                ClassifyWithGeminiJob::dispatch($updated->id)->afterCommit();
            }
        }

        return $updated->fresh(['client', 'files', 'invoices', 'subscriptions']) ?? $updated;
    }

    private function markWon(ServiceRequest $request): void
    {
        if ($request->odoo_won_at) {
            return;
        }

        $leadId = (int) ($request->client?->odoo_lead_id ?? 0);
        if ($leadId > 0 && $this->odoo->configured()) {
            $this->odoo->markLeadWon($leadId);
        }

        $request->forceFill(['odoo_won_at' => now()])->save();
    }

    private function notifyClientSuccess(ServiceRequest $request): void
    {
        $chatId = $request->client?->telegram_user_id;
        if (! filled($chatId) || ! $this->telegram->configured('client')) {
            return;
        }

        try {
            $this->telegram->send(
                (string) $chatId,
                'عملية الدفع تم بنجاح سيتم العمل على متطلباتكم ويتم المراجعة بأقرب وقت',
            );
        } catch (\Throwable $exception) {
            Log::warning('Payment success telegram failed.', [
                'request' => $request->number,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
