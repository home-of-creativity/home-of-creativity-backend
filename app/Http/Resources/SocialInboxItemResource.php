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
