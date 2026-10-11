<?php

namespace App\Http\Resources;

use App\Enums\AppRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in account as the mobile app sees it. A placeholder email is
 * never shown.
 *
 * @mixin User
 */
class AppUserResource extends JsonResource
{
    public function __construct(User $resource, private AppRole $role)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $client = $this->role === AppRole::Client ? $this->resource->client : null;
        $employee = $this->role !== AppRole::Client ? $this->resource->employee : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->resource->hasPlaceholderEmail() ? null : $this->email,
            'phone' => $this->phone,
            'locale' => $this->locale,
            'role' => $this->role->value,
            'company_name' => $client?->company_name,
            'employee_code' => $employee?->code,
            'profession' => $employee?->profession?->value,
        ];
    }
}
