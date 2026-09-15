<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Services\OdooClient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Employee */
class EmployeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'telegram_user_id' => $this->telegram_user_id,
            'telegram_username' => $this->telegram_username,
            'clickup_user_id' => $this->clickup_user_id,
            'odoo_employee_id' => $this->odoo_employee_id,
            'odoo_url' => filled($this->odoo_employee_id) && app(OdooClient::class)->configured()
                ? app(OdooClient::class)->recordUrl('hr.employee', (string) $this->odoo_employee_id)
                : null,
            'profession' => $this->profession->value,
            'status' => $this->status->value,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
