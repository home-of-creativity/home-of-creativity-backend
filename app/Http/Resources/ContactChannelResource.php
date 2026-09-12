<?php

namespace App\Http\Resources;

use App\Models\ContactChannel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ContactChannel */
class ContactChannelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'region' => $this->region,
            'platform' => $this->platform,
            'value' => $this->value,
            'value_ar' => $this->value_ar,
            'digits' => $this->digits,
            'url' => $this->url,
            'sort_order' => $this->sort_order,
            'is_published' => $this->is_published,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
