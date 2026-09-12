<?php

namespace App\Http\Resources;

use App\Models\PricingCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PricingCategory */
class PricingCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name_en' => $this->name_en,
            'name_ar' => $this->name_ar,
            'lead_en' => $this->lead_en,
            'lead_ar' => $this->lead_ar,
            'sort_order' => $this->sort_order,
            'is_published' => $this->is_published,
            'subcategories_count' => $this->whenCounted('subcategories'),
            'subcategories' => PricingSubcategoryResource::collection($this->whenLoaded('subcategories')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
