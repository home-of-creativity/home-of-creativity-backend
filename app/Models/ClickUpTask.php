<?php

namespace App\Models;

use App\Enums\ClickUpTaskType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClickUpTask extends Model
{
    protected $table = 'clickup_tasks';

    protected $fillable = [
        'request_id',
        'brief_id',
        'task_type',
        'clickup_task_id',
        'clickup_list_id',
        'clickup_user_id',
        'employee_id',
        'clickup_url',
        'status',
        'integration_key',
        'planned_hours',
        'period_key',
    ];

    protected function casts(): array
    {
        return [
            'task_type' => ClickUpTaskType::class,
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'request_id');
    }

    public function brief(): BelongsTo
    {
        return $this->belongsTo(DepartmentBrief::class, 'brief_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public static function buildIntegrationKey(string $requestUuid, ?int $briefId, ClickUpTaskType $taskType): string
    {
        $briefKey = $briefId ?? 0;

        return "{$requestUuid}:{$briefKey}:{$taskType->value}";
    }
}
