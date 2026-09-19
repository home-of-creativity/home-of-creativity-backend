<?php

namespace App\Http\Resources;

use App\Models\SocialPost;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SocialPost */
class SocialPostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'placement' => $this->placement instanceof \BackedEnum ? $this->placement->value : ($this->placement ?? 'feed'),
            'status' => $this->status->value,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'is_editable' => $this->isEditable(),
            'is_deletable' => $this->isDeletable(),
            'can_publish' => $this->canRetryPublish(),
            'created_by' => $this->whenLoaded('creator', fn () => $this->staff($this->creator)),
            'updated_by' => $this->whenLoaded('updater', fn () => $this->staff($this->updater)),
            'approved_by' => $this->whenLoaded('approver', fn () => $this->staff($this->approver)),
            'accounts' => $this->whenLoaded('accounts', fn () => $this->accounts->map(fn ($account) => [
                'id' => $account->id,
                'platform' => $account->platform->value,
                'name' => $account->name,
                'handle' => $account->handle,
                'is_active' => $account->is_active,
                'publish_status' => $account->pivot->status instanceof \BackedEnum
                    ? $account->pivot->status->value
                    : $account->pivot->status,
                'external_id' => $account->pivot->external_id,
                'published_at' => $account->pivot->published_at,
                'last_error' => $account->pivot->last_error,
            ])),
            'media' => SocialPostMediaResource::collection($this->whenLoaded('media')),
            'activities' => SocialActivityResource::collection($this->whenLoaded('activities')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function staff(mixed $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
        ];
    }
}
