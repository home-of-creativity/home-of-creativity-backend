<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PhotographyBooking extends Model
{
    /** The client picked a time and the photographer has not answered. */
    public const PENDING_STAFF = 'pending_staff';

    /** The photographer proposed a time and the client has not answered. */
    public const NEEDS_CLIENT = 'needs_client';

    public const CONFIRMED = 'confirmed';

    /** An agreed shoot moving to `proposed_starts_at`; `starts_at` stays held until the answer. */
    public const RESCHEDULING = 'rescheduling';

    public const DECLINED = 'declined';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    public const DONE = 'done';

    public const NOSHOW = 'noshow';

    /** Rows that hold a session of the balance without charging it yet. */
    public const HOLDS = [self::PENDING_STAFF, self::NEEDS_CLIENT];

    /** Rows that still wait for an answer from someone. */
    public const OPEN = [self::PENDING_STAFF, self::NEEDS_CLIENT, self::RESCHEDULING];

    protected $fillable = [
        'request_id',
        'employee_id',
        'starts_at',
        'ends_at',
        'status',
        'google_event_id',
        'proposed_starts_at',
        'reminded_at',
        'period_key',
        'session_number',
        'waiting_since',
        'confirmed_at',
        'charged_at',
        'refunded_at',
        'lead_override_by',
        'calendar_failed_at',
        'clickup_failed_at',
        'client_notified_at',
        'client_notify_failed',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'proposed_starts_at' => 'datetime',
            'reminded_at' => 'datetime',
            'waiting_since' => 'datetime',
            'confirmed_at' => 'datetime',
            'charged_at' => 'datetime',
            'refunded_at' => 'datetime',
            'calendar_failed_at' => 'datetime',
            'clickup_failed_at' => 'datetime',
            'client_notified_at' => 'datetime',
            'client_notify_failed' => 'boolean',
            'request_id' => 'integer',
            'employee_id' => 'integer',
            'session_number' => 'integer',
            'lead_override_by' => 'integer',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'request_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PhotographyBookingEvent::class, 'booking_id');
    }

    public function isCharged(): bool
    {
        return $this->charged_at !== null && $this->refunded_at === null;
    }

    /**
     * The start this row keeps off the calendar for everyone else: a hold, an
     * agreed time, or both ends of a move.
     *
     * @return list<\Illuminate\Support\Carbon>
     */
    public function heldStarts(bool $withHolds): array
    {
        return array_values(array_filter(match ($this->status) {
            self::CONFIRMED => [$this->starts_at],
            self::RESCHEDULING => [$this->starts_at, $this->proposed_starts_at],
            self::PENDING_STAFF => $withHolds ? [$this->starts_at] : [],
            self::NEEDS_CLIENT => $withHolds ? [$this->proposed_starts_at] : [],
            default => [],
        }));
    }
}
