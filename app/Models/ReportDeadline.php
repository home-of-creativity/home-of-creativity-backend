<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportDeadline extends Model
{
    public const REPEATS = ['none', 'weekly', 'monthly', 'quarterly', 'yearly'];

    protected $fillable = [
        'client_id',
        'user_id',
        'title',
        'starts_on',
        'due_time',
        'repeat',
        'repeat_until',
        'color',
        'notes',
    ];

    // starts_on and repeat_until stay "Y-m-d" strings: the date cast would store a time part
    // in SQLite and exact date lookups would miss.

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(ReportDeadlineOccurrence::class);
    }
}
