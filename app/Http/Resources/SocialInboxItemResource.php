<?php

namespace App\Http\Resources;

use App\Models\SocialInboxItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SocialInboxItem */
class SocialInboxItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'external_id' => $this->external_id,
            'source_external_id' => $this->source_external_id,
            'source_body' => $this->source_body,
            'source_permalink' => $this->source_permalink,
            'source_preview_url' => $this->source_preview_url,
            'source_media_type' => $this->source_media_type,
            'source_post' => $this->whenLoaded('sourcePost', fn () => $this->sourcePost ? [
                'id' => $this->sourcePost->id,
                'body' => $this->sourcePost->body,
                'placement' => $this->sourcePost->placement instanceof \BackedEnum
                    ? $this->sourcePost->placement->value
                    : $this->sourcePost->placement,
            ] : null),
            'author_name' => $this->author_name,
            'author_handle' => $this->author_handle,
            'body' => $this->body,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'is_replied' => $this->is_replied,
            'account' => $this->whenLoaded('account', fn () => $this->account ? [
                'id' => $this->account->id,
                'platform' => $this->account->platform->value,
                'name' => $this->account->name,
            ] : null),
            'replies' => $this->whenLoaded('replies', fn () => $this->replies->map(fn ($reply) => [
                'id' => $reply->id,
                'body' => $reply->body,
                'sent_at' => $reply->sent_at?->toIso8601String(),
                'last_error' => $reply->last_error,
                'user' => $reply->relationLoaded('user') && $reply->user
                    ? ['id' => $reply->user->id, 'name' => $reply->user->name]
                    : null,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
