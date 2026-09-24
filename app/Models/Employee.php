<?php

namespace App\Models;

use App\Enums\EmployeeProfession;
use App\Enums\EmployeeStatus;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'phone',
        'email',
        'user_id',
        'telegram_user_id',
        'telegram_username',
        'clickup_user_id',
        'odoo_employee_id',
        'profession',
        'notes',
        'status',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'profession' => EmployeeProfession::class,
            'status' => EmployeeStatus::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->active()->where('status', EmployeeStatus::Approved);
    }

    public function isApproved(): bool
    {
        return $this->status === EmployeeStatus::Approved && $this->is_active;
    }

    public function isSales(): bool
    {
        return $this->profession === EmployeeProfession::Sales;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clickupTasks(): HasMany
    {
        return $this->hasMany(ClickUpTask::class);
    }

    public function isAssignedTo(ServiceRequest $request): bool
    {
        return $request->clickupTasks()->where('employee_id', $this->id)->exists();
    }
}
