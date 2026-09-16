<?php

namespace App\Http\Requests;

use App\Models\PricingCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePricingCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('is_published')) {
            $this->merge([
                'is_published' => filter_var($this->input('is_published'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
            ]);
        }
        foreach (['requires_full_payment', 'allows_renewal'] as $key) {
            if ($this->has($key)) {
                $this->merge([
                    $key => filter_var($this->input($key), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var PricingCategory|null $category */
        $category = $this->route('pricing_category');

        return [
            'slug' => ['sometimes', 'string', 'max:60', 'alpha_dash', Rule::unique('pricing_categories', 'slug')->ignore($category?->id)],
            'name_en' => ['sometimes', 'string', 'max:120'],
            'name_ar' => ['sometimes', 'string', 'max:120'],
            'lead_en' => ['nullable', 'string', 'max:2000'],
            'lead_ar' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_published' => ['sometimes', 'boolean'],
            'requires_full_payment' => ['sometimes', 'boolean'],
            'allows_renewal' => ['sometimes', 'boolean'],
        ];
    }
}
