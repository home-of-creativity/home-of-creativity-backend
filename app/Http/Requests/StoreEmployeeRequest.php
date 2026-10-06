<?php

namespace App\Http\Requests;

use App\Enums\EmployeeProfession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['code', 'phone', 'email', 'telegram_user_id', 'telegram_username', 'clickup_user_id', 'notes'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:40', 'unique:employees,code'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:120'],
            'telegram_user_id' => ['nullable', 'string', 'max:80', 'unique:employees,telegram_user_id'],
            'telegram_username' => ['nullable', 'string', 'max:80'],
            'clickup_user_id' => ['nullable', 'string', 'max:80'],
            'profession' => ['required', 'string', Rule::enum(EmployeeProfession::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
