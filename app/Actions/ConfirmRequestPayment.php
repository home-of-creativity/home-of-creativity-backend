<?php

namespace App\Actions;

use App\Enums\GeminiStatus;
use App\Enums\PaymentMethod;
use App\Enums\RequestStatus;
use App\Enums\WorkType;
use App\Jobs\ClassifyWithGeminiJob;
use App\Models\OpsFollowUp;
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
        private SyncClientExpectedRevenue $syncClientExpectedRevenue,
    ) {}

    public function handle(ServiceRequest $request, PaymentMethod $method, float $receivedAmount): ServiceRequest
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

        if ($receivedAmount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Enter the amount actually received.',
            ]);
        }

        if ($isFirst && ($request->gemini_status === GeminiStatus::Pending || $request->gemini_status === GeminiStatus::Processing)) {
            throw ValidationException::withMessages([
                'gemini' => 'Gemini classification is already in progress.',
            ]);
        }

        $appliedAmount = 0.0;
        $updated = DB::transaction(function () use ($request, $method, $isFirst, $receivedAmount, &$appliedAmount): ServiceRequest {
            $total = (float) ($request->amount_total ?? $request->quotation_amount ?? 0);
            if ($total <= 0) {
                $total = (float) ($request->quotation_amount ?? 0);
            }

            $paid = (float) ($request->amount_paid ?? 0);
            $room = $total > 0 ? max(round($total - $paid, 2), 0) : $receivedAmount;
            $appliedAmount = $total > 0 ? min($receivedAmount, $room) : $receivedAmount;
            $newPaid = round($paid + $appliedAmount, 2);
            $newRemaining = $total > 0 ? max(round($total - $newPaid, 2), 0) : 0;

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

        if ($updated->status === RequestStatus::AwaitingPayment) {
            $updated = $this->transitions->transition(
                $updated,
                RequestStatus::PaymentConfirmed,
                'admin',
                $updated->isFullyPaid() ? 'Full payment received.' : 'Payment received.',
            );
        }

        if ($updated->isFullyPaid()) {
            $this->markWon($updated);
        }

        $this->notifyClientSuccess($updated, $isFirst);

        try {
            $this->issueInvoice->handle($updated, $appliedAmount, 'received', false);
        } catch (\Throwable) {
            // Continue locally if Odoo invoice fails.
        }

        if (blank($updated->google_drive_folder_id)) {
            $updated = $this->ensureRequestDriveFolder->handleQuietly($updated);
        }

        if ($isFirst) {
            $this->schedulePaymentReminders->handle($updated->fresh() ?? $updated);

            if ($updated->gemini_status !== GeminiStatus::Success) {
                $updated->forceFill([
                    'gemini_status' => GeminiStatus::Pending,
                    'gemini_error' => null,
                ])->save();
                try {
                    ClassifyWithGeminiJob::dispatch($updated->id)->afterCommit();
                } catch (\Throwable $exception) {
                    Log::warning('Gemini dispatch after payment failed.', [
                        'request' => $updated->number,
                        'error' => $exception->getMessage(),
                    ]);
                    $updated->forceFill([
                        'gemini_status' => GeminiStatus::Failed,
                        'gemini_error' => $exception->getMessage(),
                        'gemini_processed_at' => now(),
                    ])->save();
                }
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
        if ($leadId > 0 && $this->odoo->configured() && $request->client) {
            $this->syncClientExpectedRevenue->handle($request->client, markWon: true);
        }

        $request->forceFill(['odoo_won_at' => now()])->save();
    }

    private function notifyClientSuccess(ServiceRequest $request, bool $isFirst): void
    {
        $chatId = $request->client?->telegram_user_id;
        if (! filled($chatId) || ! $this->telegram->configured('client')) {
            return;
        }

        $text = 'عملية الدفع تم بنجاح سيتم العمل على متطلباتكم ويتم المراجعة بأقرب وقت';
        if ($isFirst) {
            $text .= "\n".$this->executionStartedLine($request);
        }

        try {
            $this->telegram->send((string) $chatId, $text);
            if ($isFirst) {
                OpsFollowUp::claim(OpsFollowUp::KIND_EXECUTION_STARTED, 'request:'.$request->id, $request);
            }
        } catch (\Throwable $exception) {
            Log::warning('Payment success telegram failed.', [
                'request' => $request->number,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function executionStartedLine(ServiceRequest $request): string
    {
        $operations = data_get($request->work_plan, 'operations', []);
        $departments = [];
        $hours = 0;
        if (is_array($operations)) {
            foreach ($operations as $operation) {
                if (! is_array($operation)) {
                    continue;
                }
                $department = trim((string) ($operation['department'] ?? ''));
                if ($department !== '') {
                    $label = NotifyPaymentStage::departmentLabel($department);
                    if (! in_array($label, $departments, true)) {
                        $departments[] = $label;
                    }
                }
                $hours += (int) ($operation['hours'] ?? 0);
            }
        }

        if ($departments === []) {
            $departments[] = match ($request->work_type) {
                WorkType::Content => 'محتوى',
                WorkType::Both => 'تصميم ومحتوى',
                default => 'تصميم',
            };
        }

        $expected = $hours > 0 ? "خلال {$hours} ساعة" : 'بأقرب وقت';

        return 'بدأ التنفيذ — '.implode('، ', $departments).' — المتوقع: '.$expected;
    }
}
