<?php

namespace App\Http\Resources;

use App\Models\PortfolioProjectImage;
use App\Support\PortfolioMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PortfolioProjectImage */
class PortfolioProjectImageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'image_path' => $this->image_path,
            'image_url' => PortfolioMedia::url($this->image_path),
            'alt_en' => $this->alt_en,
            'alt_ar' => $this->alt_ar,
            'sort_order' => $this->sort_order,
            'featured' => $this->featured,
        ];
    }
}
