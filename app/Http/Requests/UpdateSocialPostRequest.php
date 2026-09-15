<?php

namespace App\Http\Requests;

use App\Enums\SocialAbility;
use App\Enums\SocialPlacement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSocialPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canSocial(SocialAbility::Create) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('scheduled_at') === '') {
            $this->merge(['scheduled_at' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['sometimes', 'string', 'max:5000'],
            'placement' => ['sometimes', 'string', Rule::in(SocialPlacement::values())],
            'account_ids' => ['sometimes', 'array', 'min:1'],
            'account_ids.*' => ['integer', 'exists:social_accounts,id'],
            'scheduled_at' => ['nullable', 'date'],
            'intent' => ['sometimes', 'string', 'in:draft,schedule,publish'],
            'media' => ['sometimes', 'array', 'max:8'],
            'media.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif,mp4,mov,webm', 'max:51200'],
            'remove_media_ids' => ['sometimes', 'array'],
            'remove_media_ids.*' => ['integer'],
        ];
    }
}
