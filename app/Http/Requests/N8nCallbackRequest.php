<?php

namespace App\Http\Requests;

use App\Enums\WorkflowEventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class N8nCallbackRequest extends FormRequest
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
            'event' => ['required', 'string', Rule::enum(WorkflowEventType::class)],
            'request_number' => ['required', 'string', 'exists:requests,number'],
            'payload' => ['nullable', 'array'],
            'payload.ai_analysis' => ['nullable', 'array'],
            'payload.odoo_quotation_id' => ['nullable', 'string', 'max:80'],
            'payload.odoo_partner_id' => ['nullable', 'string', 'max:80'],
            'payload.odoo_invoice_id' => ['nullable', 'string', 'max:80'],
            'payload.briefs' => ['nullable', 'array'],
            'payload.briefs.*.department' => ['required_with:payload.briefs', 'string', 'max:80'],
            'payload.briefs.*.brief' => ['nullable', 'string', 'max:5000'],
            'payload.briefs.*.clickup_task_id' => ['nullable', 'string', 'max:80'],
            'payload.event_uuid' => ['nullable', 'string', 'max:80'],
            'payload.request_uuid' => ['nullable', 'string', 'max:80'],
            'payload.task_type' => ['nullable', 'string', 'max:80'],
            'payload.integration_key' => ['nullable', 'string', 'max:191'],
            'payload.clickup_task_id' => ['nullable', 'string', 'max:80'],
            'payload.clickup_list_id' => ['nullable', 'string', 'max:80'],
            'payload.clickup_user_id' => ['nullable', 'string', 'max:80'],
            'payload.clickup_url' => ['nullable', 'string', 'max:500'],
            'payload.brief_id' => ['nullable', 'integer'],
            'payload.status' => ['nullable', 'string', 'max:80'],
        ];
    }
}
