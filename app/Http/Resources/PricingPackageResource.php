<?php

namespace App\Http\Resources;

use App\Models\PricingPackage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PricingPackage */
class PricingPackageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subcategory_id' => $this->subcategory_id,
            'slug' => $this->slug,
            'name_en' => $this->name_en,
            'name_ar' => $this->name_ar,
            'subtitle_en' => $this->subtitle_en,
            'subtitle_ar' => $this->subtitle_ar,
            'price_usd' => $this->price_usd,
            'prices' => $this->prices,
            'features' => $this->features ?? [],
            'reach' => $this->reach,
            'featured' => $this->featured,
            'badge_en' => $this->badge_en,
            'badge_ar' => $this->badge_ar,
            'sort_order' => $this->sort_order,
            'is_published' => $this->is_published,
            'allows_partial_payment' => $this->allows_partial_payment,
            'subcategory' => PricingSubcategoryResource::make($this->whenLoaded('subcategory')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
