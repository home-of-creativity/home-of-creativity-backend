<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClientChannelsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'telegram_enabled' => ['required', 'boolean'],
            'whatsapp_enabled' => ['required', 'boolean'],
        ];
    }
}
