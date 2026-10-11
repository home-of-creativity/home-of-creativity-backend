<?php

namespace App\Http\Resources\App;

use App\Models\ClientReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A published report for its client. Storage paths stay on the server; the
 * PDF streams through the authenticated app route.
 *
 * @mixin ClientReport
 */
class AppReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $body = trim(strip_tags((string) $this->body));

        return [
            'id' => $this->id,
            'title' => $this->title,
            'summary' => $body === '' ? null : Str::limit(preg_replace('/\s+/u', ' ', $body) ?? $body, 220),
            'cover_url' => filled($this->cover_path) ? Storage::disk('public')->url($this->cover_path) : null,
            'has_pdf' => filled($this->pdf_path),
            'drive_url' => $this->drive_url,
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
