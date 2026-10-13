<?php

namespace App\Http\Requests;

use App\Support\OdooPaperLimits;
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
            'notes' => OdooPaperLimits::notesRule(),
            'requires_full_payment' => ['sometimes', 'boolean'],
        ] + OdooPaperLimits::lineRules('sometimes');
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
