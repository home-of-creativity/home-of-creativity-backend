<?php

namespace App\Actions;

use App\Models\ServiceRequest;
use App\Services\TelegramNotifier;
use App\Support\Money;
use App\Support\PaymentPlanResolver;
use App\Support\ResolveServiceRequest;
use App\Support\ShamCashQr;
use Illuminate\Support\Facades\Log;

class ApplyQuotationAcceptance
{
    /** @var array{caption: string, qr_available: bool, delivered: bool}|null */
    public ?array $clientNotice = null;

    public function __construct(private TelegramNotifier $telegram) {}

    public function handle(ServiceRequest $request): ServiceRequest
    {
        $total = (float) ($request->quotation_amount ?? $request->amount_total ?? 0);
        if ($total <= 0) {
            $total = (float) ($request->quotations()->latest('version')->value('amount') ?? 0);
        }

        $requiresFull = (bool) $request->requires_full_payment;
        $planLocked = in_array($request->payment_plan, ['full', 'partial'], true);
        if ($request->pricingPackage && ! $planLocked) {
            $plan = PaymentPlanResolver::forPackage($request->pricingPackage()->with('subcategory.category')->first());
            $requiresFull = $plan['requires_full_payment'];
            $request->forceFill([
                'requires_full_payment' => $plan['requires_full_payment'],
                'allows_renewal' => $plan['allows_renewal'],
                'payment_plan' => $plan['payment_plan'],
            ]);
        }

        $displayNumber = ResolveServiceRequest::displayNumber($request);
        $planNote = null;
        if ($total > 0) {
            if ($requiresFull) {
                $request->forceFill([
                    'amount_total' => $total,
                    'amount_paid' => 0,
                    'amount_remaining' => $total,
                    'payment_plan' => 'full',
                    'requires_full_payment' => true,
                    'quotation_amount' => $total,
                ])->save();
                $planNote = 'المطلوب دفع المبلغ كاملاً: '.Money::format($total);
            } else {
                $deposit = round($total * 0.5, 2);
                $request->forceFill([
                    'amount_total' => $total,
                    'amount_paid' => 0,
                    'amount_remaining' => $total,
                    'payment_plan' => 'partial',
                    'requires_full_payment' => false,
                    'quotation_amount' => $total,
                ])->save();
                $planNote = 'دفعة أولى (50%): '.Money::format($deposit).' — المتبقي لاحقاً';
            }
        }

        $caption = "تعليمات الدفع للطلب #{$displayNumber}";
        if ($total > 0) {
            $caption .= "\n".'الإجمالي: '.Money::format($total);
        }
        if (filled($planNote)) {
            $caption .= "\n{$planNote}";
        }
        $caption .= "\nحوّل عبر شام كاش باستخدام الرمز أدناه، ثم أرسل إثبات التحويل كصورة أو PDF.";

        $this->notifyClient($request, $caption);

        return $request->fresh(['client']) ?? $request;
    }

    private function notifyClient(ServiceRequest $request, string $caption): void
    {
        $qrPath = ShamCashQr::relativePath();
        $delivered = false;
        $chatId = $request->client?->telegram_user_id;

        if (filled($chatId) && $this->telegram->configured('client')) {
            try {
                if (filled($qrPath)) {
                    $this->telegram->sendPaymentQr((string) $chatId, $caption, $qrPath);
                } else {
                    $this->telegram->send((string) $chatId, $caption, 'client');
                }
                $delivered = true;
            } catch (\Throwable $exception) {
                Log::warning('Payment QR / instructions send failed.', [
                    'request' => $request->number,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->clientNotice = [
            'caption' => $caption,
            'qr_available' => filled($qrPath),
            'delivered' => $delivered,
        ];
    }
}
