<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateArticleRequest extends FormRequest
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

        if ($this->has('slug')) {
            $slug = Str::slug((string) $this->input('slug'));
            $this->merge(['slug' => $slug === '' ? null : $slug]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:191',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('articles', 'slug')->ignore($this->route('article')),
            ],
            'title_en' => ['sometimes', 'required', 'string', 'max:191'],
            'title_ar' => ['sometimes', 'required', 'string', 'max:191'],
            'excerpt_en' => ['nullable', 'string', 'max:500'],
            'excerpt_ar' => ['nullable', 'string', 'max:500'],
            'body_en' => ['sometimes', 'required', 'string'],
            'body_ar' => ['sometimes', 'required', 'string'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_published' => ['sometimes', 'boolean'],
            'published_at' => ['nullable', 'date'],
        ];
    }
}
