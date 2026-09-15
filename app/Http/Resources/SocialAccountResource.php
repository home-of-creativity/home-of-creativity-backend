<?php

namespace App\Http\Resources;

use App\Models\SocialAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SocialAccount */
class SocialAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform->value,
            'name' => $this->name,
            'handle' => $this->handle,
            'page_id' => $this->page_id,
            'facebook_page_id' => $this->facebook_page_id,
            'has_token' => $this->hasToken(),
            'is_active' => $this->is_active,
            'connection_status' => $this->connection_status->value,
            'last_error' => $this->last_error,
            'connected_by' => $this->whenLoaded('connector', fn () => $this->connector ? [
                'id' => $this->connector->id,
                'name' => $this->connector->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
