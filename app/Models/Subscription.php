<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    protected $fillable = [
        'request_id',
        'billing_period',
        'starts_at',
        'ends_at',
        'amount',
        'amount_paid',
        'amount_remaining',
        'payment_plan',
        'status',
        'renewal_declined',
    ];

    protected $attributes = [
        'status' => 'active',
        'renewal_declined' => false,
        'amount_paid' => 0,
        'amount_remaining' => 0,
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'amount_remaining' => 'decimal:2',
            'renewal_declined' => 'boolean',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'request_id');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(PaymentReminder::class);
    }
}
