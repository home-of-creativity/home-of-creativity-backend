<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendContactMessageRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180'],
            'phone' => ['nullable', 'string', 'max:40'],
            'interest' => ['nullable', 'string', 'max:80'],
            'interest_label' => ['nullable', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:4000'],
            'locale' => ['nullable', 'in:ar,en'],
        ];
    }
}
