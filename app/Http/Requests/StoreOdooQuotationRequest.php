<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOdooQuotationRequest extends FormRequest
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
            'action' => ['nullable', Rule::in(['draft', 'send'])],
            'deliver' => ['required_if:action,send', 'nullable', Rule::in(['email', 'whatsapp', 'both', 'phone'])],
            'reference' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'date_order' => ['nullable', 'date'],
            'validity_date' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.title' => ['required', 'string', 'max:255'],
            'lines.*.display_type' => ['nullable', Rule::in(['line_section', 'line_note'])],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0', 'required_unless:lines.*.display_type,line_section,line_note'],
            'lines.*.units' => ['nullable', 'numeric', 'min:0.01'],
            'lines.*.product_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
