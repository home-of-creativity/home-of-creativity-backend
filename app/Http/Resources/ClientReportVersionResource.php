<?php

namespace App\Http\Resources;

use App\Models\ClientReportVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClientReportVersion */
class ClientReportVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $report = $this->relationLoaded('report') ? $this->report : null;
        $client = $this->relationLoaded('client') ? $this->client : null;

        return [
            'id' => $this->id,
            'event' => $this->event,
            'title' => $this->title,
            'report_id' => $this->client_report_id,
            'report_title' => $report?->title,
            'client' => $client ? ['id' => $client->id, 'name' => $client->name, 'company_name' => $client->company_name] : null,
            'user' => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null,
            'saves' => $this->saves,
            'has_document' => filled($this->document_path),
            'size' => $this->size,
            'detail' => $this->detail,
            'started_at' => ($this->started_at ?? $this->created_at)?->toIso8601String(),
            'ended_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
