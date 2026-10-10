<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhotographyBookingEvent extends Model
{
    protected $fillable = [
        'booking_id',
        'action',
        'actor',
        'employee_id',
        'user_id',
        'from_status',
        'to_status',
        'starts_at',
        'proposed_starts_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'proposed_starts_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(PhotographyBooking::class, 'booking_id');
    }
}
