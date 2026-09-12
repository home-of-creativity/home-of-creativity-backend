<?php

namespace App\Http\Requests;

use App\Models\PricingSubcategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePricingSubcategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $bools = ['is_published', 'one_time', 'lead_in_box'];
        $merged = [];
        foreach ($bools as $key) {
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
        /** @var PricingSubcategory|null $subcategory */
        $subcategory = $this->route('pricing_subcategory');
        $categoryId = $this->input('category_id', $subcategory?->category_id);

        return [
            'category_id' => ['sometimes', 'integer', Rule::exists('pricing_categories', 'id')],
            'slug' => [
                'sometimes',
                'string',
                'max:60',
                'alpha_dash',
                Rule::unique('pricing_subcategories', 'slug')
                    ->where(fn ($q) => $q->where('category_id', $categoryId))
                    ->ignore($subcategory?->id),
            ],
            'name_en' => ['sometimes', 'string', 'max:120'],
            'name_ar' => ['sometimes', 'string', 'max:120'],
            'lead_en' => ['nullable', 'string', 'max:2000'],
            'lead_ar' => ['nullable', 'string', 'max:2000'],
            'one_time' => ['sometimes', 'boolean'],
            'lead_in_box' => ['sometimes', 'boolean'],
            'lead_note_en' => ['nullable', 'string', 'max:500'],
            'lead_note_ar' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_published' => ['sometimes', 'boolean'],
        ];
    }
}
