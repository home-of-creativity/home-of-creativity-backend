<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class AppOtpVerifyRequest extends FormRequest
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
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'digits:'.(int) config('auth.app.otp_length')],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
