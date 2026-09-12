<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePortfolioProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['summary_en', 'summary_ar'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }

        if ($this->has('category_id')) {
            $this->merge(['category_id' => (int) $this->input('category_id')]);
        }

        if ($this->has('is_published')) {
            $this->merge([
                'is_published' => in_array($this->input('is_published'), [true, 1, '1', 'true', 'on'], true),
            ]);
        }

        if ($this->has('featured')) {
            $this->merge([
                'featured' => in_array($this->input('featured'), [true, 1, '1', 'true', 'on'], true),
            ]);
        }

        if ($this->input('website_url') === '') {
            $this->merge(['website_url' => null]);
        }

        if ($this->has('social_links') || collect(['instagram', 'facebook', 'linkedin', 'x', 'tiktok', 'youtube'])->contains(fn ($platform) => $this->has("social_{$platform}"))) {
            $this->merge([
                'social_links' => $this->normalizedSocialLinks(),
            ]);
        }
    }

    /**
     * @return array<string, string>|null
     */
    private function normalizedSocialLinks(): ?array
    {
        $links = [];
        foreach (['instagram', 'facebook', 'linkedin', 'x', 'tiktok', 'youtube'] as $platform) {
            $value = $this->input("social_links.{$platform}") ?? $this->input("social_{$platform}");
            if (is_string($value) && $value !== '') {
                $links[$platform] = $value;
            }
        }

        return $links === [] ? null : $links;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['sometimes', 'required', 'integer', 'exists:portfolio_categories,id'],
            'title_en' => ['sometimes', 'required', 'string', 'max:160'],
            'title_ar' => ['sometimes', 'required', 'string', 'max:160'],
            'summary_en' => ['nullable', 'string', 'max:500'],
            'summary_ar' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_published' => ['sometimes', 'boolean'],
            'featured' => ['sometimes', 'boolean'],
            'website_url' => ['nullable', 'url', 'max:255'],
            'social_links' => ['nullable', 'array'],
            'social_links.instagram' => ['nullable', 'url', 'max:255'],
            'social_links.facebook' => ['nullable', 'url', 'max:255'],
            'social_links.linkedin' => ['nullable', 'url', 'max:255'],
            'social_links.x' => ['nullable', 'url', 'max:255'],
            'social_links.tiktok' => ['nullable', 'url', 'max:255'],
            'social_links.youtube' => ['nullable', 'url', 'max:255'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'gallery' => ['sometimes', 'array', 'max:20'],
            'gallery.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_gallery_ids' => ['sometimes', 'array'],
            'remove_gallery_ids.*' => ['integer', 'exists:portfolio_project_images,id'],
        ];
    }
}
