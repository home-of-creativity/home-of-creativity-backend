<?php

namespace App\Http\Resources\App;

use App\Enums\RequestStatus;
use App\Models\Quotation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One quotation sent to the client, with whether it still waits for them.
 *
 * @mixin Quotation
 */
class AppQuotationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $serviceRequest = $this->request;
        $decision = $this->decisions->sortByDesc('id')->first()?->decision?->value;
        $latestVersion = (int) ($serviceRequest?->quotations_max_version ?? $this->version);
        $isLatest = (int) $this->version >= $latestVersion;

        $state = match (true) {
            $decision !== null => $decision,
            $isLatest && $serviceRequest?->status === RequestStatus::QuotationSent => 'pending',
            default => 'superseded',
        };

        return [
            'id' => $this->id,
            'version' => (int) $this->version,
            'amount' => $this->amount !== null ? (float) $this->amount : null,
            'currency' => 'USD',
            'notes' => $this->notes,
            'state' => $state,
            'has_pdf' => filled($this->pdf_path),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'request' => $serviceRequest ? [
                'id' => $serviceRequest->id,
                'number' => $serviceRequest->number,
                'title' => $serviceRequest->title,
            ] : null,
        ];
    }
}
