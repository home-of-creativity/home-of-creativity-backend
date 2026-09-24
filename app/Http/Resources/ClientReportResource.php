<?php

namespace App\Http\Resources;

use App\Models\ClientReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin ClientReport */
class ClientReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'title' => $this->title,
            'header' => $this->header,
            'footer' => $this->footer,
            'body' => $this->body,
            'cover_url' => filled($this->cover_path) ? Storage::disk('public')->url($this->cover_path) : null,
            'watermark_url' => filled($this->watermark_path) ? Storage::disk('public')->url($this->watermark_path) : null,
            'drive_file_id' => $this->drive_file_id,
            'drive_url' => $this->drive_url,
            'attachments' => $this->whenLoaded('attachments', fn () => $this->attachments->map(fn ($file): array => [
                'id' => $file->id,
                'name' => $file->original_name,
                'size' => $file->size,
                'url' => Storage::disk('public')->url($file->path),
                'drive_url' => $file->drive_url,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
