<?php

namespace App\Http\Resources;

use App\Models\LegalPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LegalPage */
class LegalPageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'title_ar' => $this->title_ar,
            'title_en' => $this->title_en,
            'sections' => $this->sections,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
