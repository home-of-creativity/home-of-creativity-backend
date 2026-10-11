<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class AppRefreshRequest extends FormRequest
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
            'refresh_token' => ['required', 'string', 'max:200'],
        ];
    }
}
