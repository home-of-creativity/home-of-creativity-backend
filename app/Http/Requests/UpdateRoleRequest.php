<?php

namespace App\Http\Requests;

use App\Enums\StaffAbility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('abilities'))) {
            $this->merge([
                'abilities' => StaffAbility::expand($this->input('abilities')),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $roleId = $this->route('role')?->id;

        return [
            'name' => ['required', 'string', 'max:80', Rule::unique('roles', 'name')->ignore($roleId)],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(StaffAbility::values())],
        ];
    }
}
