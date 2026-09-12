<?php

namespace App\Http\Requests;

use App\Enums\SocialAbility;
use App\Enums\SocialPlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSocialAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canSocial(SocialAbility::Accounts) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['handle', 'page_id', 'access_token', 'refresh_token'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }

        if ($this->has('is_active')) {
            $this->merge([
                'is_active' => in_array($this->input('is_active'), [true, 1, '1', 'true', 'on'], true),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'platform' => ['required', 'string', Rule::in(SocialPlatform::values())],
            'name' => ['required', 'string', 'max:120'],
            'handle' => ['nullable', 'string', 'max:120'],
            'page_id' => ['nullable', 'string', 'max:120'],
            'access_token' => ['nullable', 'string', 'max:4000'],
            'refresh_token' => ['nullable', 'string', 'max:4000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
