<?php

namespace App\Http\Resources;

use App\Models\PricingSubcategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PricingSubcategory */
class PricingSubcategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'slug' => $this->slug,
            'name_en' => $this->name_en,
            'name_ar' => $this->name_ar,
            'lead_en' => $this->lead_en,
            'lead_ar' => $this->lead_ar,
            'one_time' => $this->one_time,
            'lead_in_box' => $this->lead_in_box,
            'lead_note_en' => $this->lead_note_en,
            'lead_note_ar' => $this->lead_note_ar,
            'sort_order' => $this->sort_order,
            'is_published' => $this->is_published,
            'packages_count' => $this->whenCounted('packages'),
            'category' => PricingCategoryResource::make($this->whenLoaded('category')),
            'packages' => PricingPackageResource::collection($this->whenLoaded('packages')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
