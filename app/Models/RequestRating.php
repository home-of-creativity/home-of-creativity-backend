<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestRating extends Model
{
    /** At or below this score the client is asked why and management is alerted. */
    public const LOW = 3;

    protected $fillable = [
        'request_id',
        'client_id',
        'score',
        'reason',
        'channel',
        'asked_at',
        'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'request_id' => 'integer',
            'client_id' => 'integer',
            'score' => 'integer',
            'asked_at' => 'datetime',
            'answered_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'request_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function isLow(): bool
    {
        return $this->score !== null && $this->score <= self::LOW;
    }
}
