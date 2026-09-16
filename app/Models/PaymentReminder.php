<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentReminder extends Model
{
    public const KIND_REMAINING = 'remaining_balance';

    public const KIND_RENEWAL = 'renewal';

    public const MAX_SENDS = 5;

    protected $fillable = [
        'request_id',
        'subscription_id',
        'kind',
        'due_at',
        'last_sent_at',
        'send_count',
        'google_event_id',
        'completed_at',
    ];

    protected $attributes = [
        'send_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'completed_at' => 'datetime',
            'send_count' => 'integer',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'request_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function isDue(): bool
    {
        if ($this->completed_at !== null) {
            return false;
        }

        if ($this->send_count >= self::MAX_SENDS) {
            return false;
        }

        if ($this->due_at === null || $this->due_at->isFuture()) {
            return false;
        }

        if ($this->last_sent_at === null) {
            return true;
        }

        return $this->last_sent_at->copy()->addDays(3)->lte(now());
    }
}
