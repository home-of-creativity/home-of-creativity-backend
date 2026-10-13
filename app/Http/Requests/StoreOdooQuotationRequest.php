<?php

namespace App\Http\Requests;

use App\Support\OdooPaperLimits;
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
            'notes' => OdooPaperLimits::notesRule(),
            'date_order' => ['nullable', 'date'],
            'validity_date' => ['nullable', 'date'],
            'payment_term_id' => ['nullable', 'integer', 'min:0'],
        ] + OdooPaperLimits::lineRules();
    }
}
