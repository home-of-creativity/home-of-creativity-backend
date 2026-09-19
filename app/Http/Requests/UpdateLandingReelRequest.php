<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLandingReelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('is_published')) {
            $this->merge([
                'is_published' => in_array($this->input('is_published'), [true, 1, '1', 'true', 'on'], true),
            ]);
        }

        if ($this->has('remove_poster')) {
            $this->merge([
                'remove_poster' => in_array($this->input('remove_poster'), [true, 1, '1', 'true', 'on'], true),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title_en' => ['sometimes', 'required', 'string', 'max:160'],
            'title_ar' => ['sometimes', 'required', 'string', 'max:160'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_published' => ['sometimes', 'boolean'],
            'video' => ['nullable', 'file', 'mimes:mp4,m4v,mov,webm,qt', 'max:524288'],
            'poster' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_poster' => ['sometimes', 'boolean'],
        ];
    }
}
