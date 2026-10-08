<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOdooInvoiceRequest extends FormRequest
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
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'quotation_id' => ['nullable', 'integer', 'min:1'],
            'reference' => ['nullable', 'string', 'max:64'],
            'lines' => ['required_without:quotation_id', 'array', 'min:1', 'max:20'],
            'lines.*.title' => ['required', 'string', 'max:255'],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01'],
            'lines.*.units' => ['nullable', 'numeric', 'min:0.01'],
            'lines.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
