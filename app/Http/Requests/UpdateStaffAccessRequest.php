<?php

namespace App\Http\Requests;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStaffAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Employee|null $employee */
        $employee = $this->route('employee');
        $needsPassword = $this->filled('role_id') && $employee?->user_id === null;

        return [
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'page_keys' => ['sometimes', 'array'],
            'page_keys.*' => ['string', 'max:160'],
            'password' => [$needsPassword ? 'required' : 'nullable', 'string', 'min:8', 'max:120'],
        ];
    }
}
