<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EditReportWithGeminiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('body'))) {
            $this->merge(['body' => StoreClientReportRequest::cleanHtml($this->string('body')->toString())]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'instruction' => ['required', 'string', 'max:2000'],
            'body' => ['required', 'string', 'max:200000'],
            'scope' => ['required', Rule::in(['all', 'page'])],
            'page' => ['required_if:scope,page', 'integer', 'min:1', 'max:40'],
            'save_memory' => ['sometimes', 'boolean'],
        ];
    }
}
