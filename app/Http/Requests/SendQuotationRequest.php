<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required_without:lines', 'nullable', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['sometimes', 'array', 'min:1', 'max:20'],
            'lines.*.title' => ['required', 'string', 'max:255'],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01'],
            'lines.*.units' => ['nullable', 'numeric', 'min:0.01'],
            'lines.*.notes' => ['nullable', 'string', 'max:2000'],
            'requires_full_payment' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('requires_full_payment')) {
            $this->merge([
                'requires_full_payment' => filter_var($this->input('requires_full_payment'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            ]);
        }
    }
}
