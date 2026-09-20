<?php

namespace App\Http\Resources;

use App\Models\DriveDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DriveDelivery */
class DriveDeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mime_type' => $this->mime_type,
            'drive_file_id' => $this->drive_file_id,
            'status' => $this->clientDeliveryStatus(),
            'status_label' => $this->clientDeliveryLabelAr(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'client_approved_at' => $this->client_approved_at?->toIso8601String(),
            'client_approved' => $this->client_approved_at !== null,
            'failed_at' => $this->failed_at?->toIso8601String(),
            'fail_reason' => $this->fail_reason,
            'telegram_message_id' => $this->telegram_message_id,
            'drive_modified_at' => $this->drive_modified_at?->toIso8601String(),
        ];
    }
}
