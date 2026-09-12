<?php

namespace App\Http\Resources;

use App\Models\SocialPostMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SocialPostMedia */
class SocialPostMediaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url(),
            'original_name' => $this->original_name,
            'mime' => $this->mime,
            'kind' => $this->kind,
            'sort_order' => $this->sort_order,
        ];
    }
}
