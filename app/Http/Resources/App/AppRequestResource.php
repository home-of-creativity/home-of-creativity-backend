<?php

namespace App\Http\Resources\App;

use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A request as its client sees it in the app. Internal fields (work plan,
 * AI analysis, integration errors, staff notes) never leave the server.
 *
 * @mixin ServiceRequest
 */
class AppRequestResource extends JsonResource
{
    public bool $detailed = false;

    public function detailed(): static
    {
        $this->detailed = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = AppLocale::from($request);
        $status = $this->status;
        $quotation = $this->relationLoaded('quotations')
            ? $this->quotations->sortByDesc('version')->first()
            : null;

        $data = [
            'id' => $this->id,
            'number' => $this->number,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $status->value,
            'status_label' => $locale === 'en' ? $status->labelEn() : $status->labelAr(),
            'stage' => self::stage($status),
            'package_name' => $this->relationLoaded('pricingPackage') && $this->pricingPackage
                ? ($locale === 'en' ? ($this->pricingPackage->name_en ?: $this->pricingPackage->name_ar) : ($this->pricingPackage->name_ar ?: $this->pricingPackage->name_en))
                : null,
            'billing_period' => $this->billing_period,
            'currency' => 'USD',
            'amount_total' => $this->amount_total !== null ? (float) $this->amount_total : null,
            'amount_paid' => $this->amount_paid !== null ? (float) $this->amount_paid : null,
            'amount_remaining' => $this->amount_remaining !== null ? (float) $this->amount_remaining : null,
            'paid_percent' => $this->paidPercent(),
            'awaiting_decision' => $status === RequestStatus::QuotationSent,
            'latest_quotation' => $quotation ? [
                'id' => $quotation->id,
                'version' => (int) $quotation->version,
                'amount' => $quotation->amount !== null ? (float) $quotation->amount : null,
                'sent_at' => $quotation->sent_at?->toIso8601String(),
                'has_pdf' => filled($quotation->pdf_path),
            ] : null,
            'subscription_ends_at' => $this->subscription_ends_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if (! $this->detailed) {
            return $data;
        }

        $data['timeline'] = $this->relationLoaded('statusHistory')
            ? $this->statusHistory
                ->sortBy('id')
                ->map(function ($row) use ($locale): ?array {
                    $to = RequestStatus::tryFrom((string) $row->to_status);

                    return $to ? [
                        'status' => $to->value,
                        'label' => $locale === 'en' ? $to->labelEn() : $to->labelAr(),
                        'at' => $row->created_at?->toIso8601String(),
                    ] : null;
                })
                ->filter()
                ->values()
                ->all()
            : [];

        $data['files'] = $this->relationLoaded('driveDeliveries')
            ? $this->driveDeliveries
                ->whereNotNull('sent_at')
                ->sortByDesc('sent_at')
                ->map(fn ($file): array => [
                    'id' => $file->id,
                    'name' => $file->name,
                    'sent_at' => $file->sent_at?->toIso8601String(),
                    'approved' => $file->client_approved_at !== null,
                ])
                ->values()
                ->all()
            : [];

        $data['quotations'] = $this->relationLoaded('quotations')
            ? $this->quotations
                ->sortByDesc('version')
                ->map(fn ($row): array => [
                    'id' => $row->id,
                    'version' => (int) $row->version,
                    'amount' => $row->amount !== null ? (float) $row->amount : null,
                    'decision' => $row->relationLoaded('decisions')
                        ? $row->decisions->sortByDesc('id')->first()?->decision?->value
                        : null,
                    'sent_at' => $row->sent_at?->toIso8601String(),
                ])
                ->values()
                ->all()
            : [];

        return $data;
    }

    /**
     * 0 received, 1 quotation, 2 payment, 3 work, 4 done, -1 cancelled.
     */
    public static function stage(RequestStatus $status): int
    {
        return match ($status) {
            RequestStatus::Submitted => 0,
            RequestStatus::QuotationSent, RequestStatus::QuotationRejected => 1,
            RequestStatus::AwaitingPayment => 2,
            RequestStatus::PaymentConfirmed,
            RequestStatus::InProgress,
            RequestStatus::ReadyForReview,
            RequestStatus::RevisionRequested => 3,
            RequestStatus::Completed => 4,
            RequestStatus::Cancelled => -1,
        };
    }
}
