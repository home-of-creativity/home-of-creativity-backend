<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class AppSocialLoginRequest extends FormRequest
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
            'id_token' => ['required', 'string', 'max:4096'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
