<?php

namespace App\Http\Resources;

use App\Models\LandingReel;
use App\Support\PortfolioMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LandingReel */
class LandingReelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title_en' => $this->title_en,
            'title_ar' => $this->title_ar,
            'video_path' => $this->video_path,
            'video_url' => PortfolioMedia::url($this->video_path),
            'poster_path' => $this->poster_path,
            'poster_url' => PortfolioMedia::url($this->poster_path),
            'sort_order' => $this->sort_order,
            'is_published' => $this->is_published,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
