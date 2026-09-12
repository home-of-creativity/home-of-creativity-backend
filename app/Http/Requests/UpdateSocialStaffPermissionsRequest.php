<?php

namespace App\Http\Requests;

use App\Enums\SocialAbility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSocialStaffPermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canSocial(SocialAbility::Accounts) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'social_permissions' => ['nullable', 'array'],
            'social_permissions.*' => ['string', Rule::in(SocialAbility::values())],
        ];
    }
}
