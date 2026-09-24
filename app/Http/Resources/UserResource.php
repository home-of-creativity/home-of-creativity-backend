<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('role');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'locale' => $this->locale,
            'is_admin' => (bool) $this->is_admin,
            'role' => $this->role_id ? [
                'id' => $this->role_id,
                'name' => $this->role?->name,
            ] : null,
            'abilities' => $this->abilities(),
            'social_permissions' => $this->social_permissions,
            'social_abilities' => $this->socialAbilities(),
            'sees_all_social_pages' => $this->seesAllSocialPages(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
