<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DrivePollRequest extends FormRequest
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
            'request_number' => ['nullable', 'string', 'max:64'],
            'drive_folder_id' => ['nullable', 'string', 'max:128'],
            'drive_file_id' => ['nullable', 'string', 'max:128'],
            'drive_file_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
