<?php

namespace App\Http\Requests;

use App\Enums\SocialAbility;
use App\Enums\SocialPlacement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSocialPostRequest extends FormRequest
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

        if (! $this->exists('body') || $this->input('body') === null) {
            $this->merge(['body' => '']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:5000'],
            'placement' => ['sometimes', 'string', Rule::in(SocialPlacement::values())],
            'account_ids' => ['required', 'array', 'min:1'],
            'account_ids.*' => ['integer', 'exists:social_accounts,id'],
            'scheduled_at' => ['nullable', 'date'],
            'intent' => ['sometimes', 'string', 'in:draft,schedule,publish'],
            'media' => ['sometimes', 'array', 'max:8'],
            'media.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif,mp4,mov,webm', 'max:51200'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $intent = (string) $this->input('intent', 'draft');
            if ($intent === 'draft') {
                return;
            }

            $body = trim((string) $this->input('body', ''));
            $media = $this->file('media', []);
            $hasMedia = is_array($media) ? $media !== [] : $media !== null;
            if ($body === '' && ! $hasMedia) {
                $validator->errors()->add('body', 'Add a caption or a photo/video before publishing.');
            }
        });
    }
}
