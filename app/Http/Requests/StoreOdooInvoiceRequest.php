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
            'request_id' => ['required_if:action,post', 'nullable', 'integer', Rule::exists('requests', 'id')],
            'action' => ['nullable', Rule::in(['draft', 'post'])],
            'deliver' => ['required_if:action,post', 'nullable', Rule::in(['email', 'phone'])],
            'quotation_id' => ['nullable', 'integer', 'min:1'],
            'reference' => ['nullable', 'string', 'max:64'],
            'invoice_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'lines' => ['required_without:quotation_id', 'array', 'min:1', 'max:20'],
            'lines.*.title' => ['required', 'string', 'max:255'],
            'lines.*.display_type' => ['nullable', Rule::in(['line_section', 'line_note'])],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0', 'required_unless:lines.*.display_type,line_section,line_note'],
            'lines.*.units' => ['nullable', 'numeric', 'min:0.01'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
