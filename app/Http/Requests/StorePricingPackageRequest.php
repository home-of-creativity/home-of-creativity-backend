<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePricingPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merged = [];
        foreach (['is_published', 'featured'] as $key) {
            if ($this->has($key)) {
                $merged[$key] = filter_var($this->input($key), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
            }
        }
        if ($merged !== []) {
            $this->merge($merged);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $subcategoryId = $this->input('subcategory_id');

        return [
            'subcategory_id' => ['required', 'integer', Rule::exists('pricing_subcategories', 'id')],
            'slug' => [
                'required',
                'string',
                'max:60',
                'alpha_dash',
                Rule::unique('pricing_packages', 'slug')->where(fn ($q) => $q->where('subcategory_id', $subcategoryId)),
            ],
            'name_en' => ['required', 'string', 'max:120'],
            'name_ar' => ['required', 'string', 'max:120'],
            'subtitle_en' => ['required', 'string', 'max:160'],
            'subtitle_ar' => ['required', 'string', 'max:160'],
            'price_usd' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'prices' => ['nullable', 'array'],
            'prices.monthly' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'prices.quarterly' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'prices.semiannual' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'prices.yearly' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'features' => ['nullable', 'array'],
            'features.*.en' => ['required_with:features', 'string', 'max:500'],
            'features.*.ar' => ['required_with:features', 'string', 'max:500'],
            'reach' => ['nullable', 'array'],
            'reach.adBudgetUsd' => ['nullable', 'integer', 'min:0'],
            'reach.adCreditUsd' => ['nullable', 'integer', 'min:0'],
            'reach.estimatedReach' => ['nullable', 'array'],
            'reach.estimatedReach.en' => ['nullable', 'string', 'max:120'],
            'reach.estimatedReach.ar' => ['nullable', 'string', 'max:120'],
            'reach.goal' => ['nullable', 'array'],
            'reach.goal.en' => ['nullable', 'string', 'max:500'],
            'reach.goal.ar' => ['nullable', 'string', 'max:500'],
            'featured' => ['sometimes', 'boolean'],
            'badge_en' => ['nullable', 'string', 'max:80'],
            'badge_ar' => ['nullable', 'string', 'max:80'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_published' => ['sometimes', 'boolean'],
        ];
    }
}
