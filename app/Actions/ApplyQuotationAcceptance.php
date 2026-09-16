<?php

namespace App\Actions;

use App\Models\OpsSetting;
use App\Models\ServiceRequest;
use App\Services\TelegramNotifier;
use App\Support\PaymentPlanResolver;
use App\Support\ResolveServiceRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ApplyQuotationAcceptance
{
    public function __construct(private TelegramNotifier $telegram) {}

    public function handle(ServiceRequest $request): ServiceRequest
    {
        $total = (float) ($request->quotation_amount ?? $request->amount_total ?? 0);
        if ($total <= 0) {
            return $request;
        }

        $requiresFull = (bool) $request->requires_full_payment;
        if ($request->pricingPackage) {
            $plan = PaymentPlanResolver::forPackage($request->pricingPackage()->with('subcategory.category')->first());
            $requiresFull = $plan['requires_full_payment'];
            $request->forceFill([
                'requires_full_payment' => $plan['requires_full_payment'],
                'allows_renewal' => $plan['allows_renewal'],
                'payment_plan' => $plan['payment_plan'],
            ]);
        }

        if ($requiresFull) {
            $request->forceFill([
                'amount_total' => $total,
                'amount_paid' => 0,
                'amount_remaining' => $total,
                'payment_plan' => 'full',
                'requires_full_payment' => true,
            ])->save();
            $due = $total;
            $planNote = 'المطلوب دفع المبلغ كاملاً';
        } else {
            $deposit = round($total * 0.5, 2);
            $request->forceFill([
                'amount_total' => $total,
                'amount_paid' => 0,
                'amount_remaining' => $total,
                'payment_plan' => 'partial',
                'requires_full_payment' => false,
            ])->save();
            $due = $deposit;
            $planNote = 'دفعة أولى (50%): '.number_format($deposit, 2).' — المتبقي لاحقاً';
        }

        $chatId = $request->client?->telegram_user_id;
        if (! filled($chatId) || ! $this->telegram->configured('client')) {
            return $request->fresh(['client']) ?? $request;
        }

        $displayNumber = ResolveServiceRequest::displayNumber($request);
        $caption = "تعليمات الدفع للطلب #{$displayNumber}\n"
            .'الإجمالي: '.number_format($total, 2)." SYP\n"
            ."{$planNote}\n"
            .'أرسل وصل الدفع كصورة أو PDF بعد التحويل.';

        $qrPath = OpsSetting::getValue('sham_cash_qr_path');
        try {
            if (filled($qrPath) && Storage::disk('local')->exists($qrPath)) {
                $this->telegram->sendPaymentQr((string) $chatId, $caption, $qrPath);
            } else {
                $this->telegram->send((string) $chatId, $caption, 'client');
            }
        } catch (\Throwable $exception) {
            Log::warning('Payment QR / instructions send failed.', [
                'request' => $request->number,
                'error' => $exception->getMessage(),
            ]);
        }

        return $request->fresh(['client']) ?? $request;
    }
}
