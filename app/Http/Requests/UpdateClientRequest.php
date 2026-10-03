<?php

namespace App\Http\Requests;

use App\Models\Client;
use App\Support\ClientProfileValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['email', 'phone', 'telegram_user_id', 'company_name', 'company_activity'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $client = $this->route('client');
        $clientId = $client instanceof Client ? $client->id : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'telegram_user_id' => ['nullable', 'string', 'max:40', Rule::unique('clients', 'telegram_user_id')->ignore($clientId)],
            'company_name' => ['nullable', 'string', 'max:160'],
            'company_activity' => ['nullable', 'string', 'max:'.ClientProfileValue::ACTIVITY_MAX_LENGTH],
            'locale' => ['nullable', 'string', 'in:ar,en'],
        ];
    }
}
