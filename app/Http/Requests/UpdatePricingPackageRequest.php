<?php

namespace App\Http\Requests;

use App\Models\PricingPackage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePricingPackageRequest extends FormRequest
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
        if ($this->has('allows_partial_payment')) {
            $value = $this->input('allows_partial_payment');
            $merged['allows_partial_payment'] = $value === null || $value === ''
                ? null
                : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
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
        /** @var PricingPackage|null $package */
        $package = $this->route('pricing_package');
        $subcategoryId = $this->input('subcategory_id', $package?->subcategory_id);

        return [
            'subcategory_id' => ['sometimes', 'integer', Rule::exists('pricing_subcategories', 'id')],
            'slug' => [
                'sometimes',
                'string',
                'max:60',
                'alpha_dash',
                Rule::unique('pricing_packages', 'slug')
                    ->where(fn ($q) => $q->where('subcategory_id', $subcategoryId))
                    ->ignore($package?->id),
            ],
            'name_en' => ['sometimes', 'string', 'max:120'],
            'name_ar' => ['sometimes', 'string', 'max:120'],
            'subtitle_en' => ['sometimes', 'string', 'max:160'],
            'subtitle_ar' => ['sometimes', 'string', 'max:160'],
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
            'allows_partial_payment' => ['nullable', 'boolean'],
        ];
    }
}
