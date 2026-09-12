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
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'locale' => $this->locale,
            'is_admin' => (bool) $this->is_admin,
            'social_permissions' => $this->social_permissions,
            'social_abilities' => $this->socialAbilities(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
