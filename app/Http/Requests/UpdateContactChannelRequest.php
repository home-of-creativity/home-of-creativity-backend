<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContactChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['region', 'platform', 'value_ar', 'digits', 'url'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }

        if ($this->filled('digits')) {
            $this->merge(['digits' => preg_replace('/\D+/', '', (string) $this->input('digits'))]);
        }

        if ($this->has('is_published')) {
            $this->merge([
                'is_published' => in_array($this->input('is_published'), [true, 1, '1', 'true', 'on'], true),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $kind = $this->input('kind', $this->route('contact_channel')?->kind);
        $kindChanging = $this->has('kind');

        return [
            'kind' => ['sometimes', 'required', 'string', Rule::in(['mobile', 'whatsapp', 'social', 'location'])],
            'region' => [Rule::requiredIf($kindChanging && in_array($kind, ['mobile', 'whatsapp', 'location'], true)), 'nullable', 'string', Rule::in(['SYR', 'KSA'])],
            'platform' => [Rule::requiredIf($kindChanging && $kind === 'social'), 'nullable', 'string', Rule::in(['instagram', 'facebook', 'linkedin', 'x', 'tiktok', 'youtube'])],
            'value' => ['sometimes', 'required', 'string', 'max:160'],
            'value_ar' => [Rule::requiredIf($kindChanging && $kind === 'location'), 'nullable', 'string', 'max:160'],
            'digits' => [Rule::requiredIf($kindChanging && in_array($kind, ['mobile', 'whatsapp'], true)), 'nullable', 'string', 'max:20'],
            'url' => [Rule::requiredIf($kindChanging && $kind === 'social'), 'nullable', 'url', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_published' => ['sometimes', 'boolean'],
        ];
    }
}
