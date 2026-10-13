<?php

namespace App\Http\Requests;

use App\Support\OdooPaperLimits;
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
            'action' => ['nullable', Rule::in(['draft', 'post'])],
            'deliver' => ['required_if:action,post', 'nullable', Rule::in(['email', 'whatsapp', 'both', 'phone'])],
            'quotation_id' => ['nullable', 'integer', 'min:1'],
            'reference' => ['nullable', 'string', 'max:64'],
            'invoice_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'payment_term_id' => ['nullable', 'integer', 'min:0'],
        ] + OdooPaperLimits::lineRules('required_without:quotation_id');
    }
}
