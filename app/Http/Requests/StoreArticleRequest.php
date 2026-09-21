<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreArticleRequest extends FormRequest
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
            'slug' => ['nullable', 'string', 'max:191', 'regex:/^[a-z0-9-]+$/', 'unique:articles,slug'],
            'title_en' => ['required', 'string', 'max:191'],
            'title_ar' => ['required', 'string', 'max:191'],
            'excerpt_en' => ['nullable', 'string', 'max:500'],
            'excerpt_ar' => ['nullable', 'string', 'max:500'],
            'body_en' => ['required', 'string'],
            'body_ar' => ['required', 'string'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_published' => ['sometimes', 'boolean'],
            'published_at' => ['nullable', 'date'],
        ];
    }
}
