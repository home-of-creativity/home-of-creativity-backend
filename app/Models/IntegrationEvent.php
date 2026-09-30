<?php

namespace App\Models;

use App\Enums\IntegrationEventStatus;
use App\Enums\WorkflowEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationEvent extends Model
{
    protected $fillable = [
        'event_uuid',
        'event_type',
        'request_uuid',
        'request_number',
        'correlation_id',
        'aggregate_version',
        'payload',
        'status',
        'attempts',
        'last_error',
        'next_retry_at',
        'dispatched_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => WorkflowEventType::class,
            'payload' => 'array',
            'status' => IntegrationEventStatus::class,
            'next_retry_at' => 'datetime',
            'dispatched_at' => 'datetime',
        ];
    }

    public function stopsRetry(): bool
    {
        return $this->attempts >= 8 || self::errorIsPermanent((string) $this->last_error);
    }

    public static function errorIsPermanent(string $error): bool
    {
        if (! preg_match('/HTTP (\d{3})/', $error, $match)) {
            return false;
        }

        $code = (int) $match[1];

        return $code >= 400 && $code < 500 && ! in_array($code, [408, 429], true);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'request_uuid', 'uuid');
    }
}
