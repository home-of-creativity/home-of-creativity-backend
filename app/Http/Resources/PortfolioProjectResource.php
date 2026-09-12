<?php

namespace App\Http\Resources;

use App\Models\PortfolioProject;
use App\Support\PortfolioMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PortfolioProject */
class PortfolioProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'category' => PortfolioCategoryResource::make($this->whenLoaded('category')),
            'title_en' => $this->title_en,
            'title_ar' => $this->title_ar,
            'summary_en' => $this->summary_en,
            'summary_ar' => $this->summary_ar,
            'website_url' => $this->website_url,
            'social_links' => $this->social_links ?? [],
            'image_path' => $this->image_path,
            'image_url' => PortfolioMedia::url($this->image_path),
            'images' => PortfolioProjectImageResource::collection($this->whenLoaded('images')),
            'sort_order' => $this->sort_order,
            'is_published' => $this->is_published,
            'featured' => $this->featured,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
