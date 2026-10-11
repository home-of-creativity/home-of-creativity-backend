<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class AppOtpSendRequest extends FormRequest
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
            'phone' => ['required', 'string', 'min:8', 'max:24', 'regex:/^\+?[0-9 ()\-]{8,24}$/'],
        ];
    }
}
