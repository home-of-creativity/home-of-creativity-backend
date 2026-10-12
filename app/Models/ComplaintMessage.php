<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line on a complaint ticket: a staff reply, a client answer, or an internal note. */
class ComplaintMessage extends Model
{
    public const STAFF = 'staff';

    public const CLIENT = 'client';

    protected $fillable = [
        'complaint_id',
        'author',
        'user_id',
        'channel',
        'body',
        'delivered',
    ];

    protected function casts(): array
    {
        return [
            'delivered' => 'boolean',
        ];
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
