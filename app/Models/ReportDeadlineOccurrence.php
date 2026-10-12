<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportDeadlineOccurrence extends Model
{
    protected $fillable = [
        'report_deadline_id',
        'occurs_on',
        'moved_on',
        'moved_time',
        'skipped',
        'done_at',
        'done_by',
        'client_report_id',
    ];

    protected function casts(): array
    {
        return [
            // occurs_on and moved_on stay "Y-m-d" strings (see ReportDeadline).
            'skipped' => 'boolean',
            'done_at' => 'datetime',
        ];
    }

    public function deadline(): BelongsTo
    {
        return $this->belongsTo(ReportDeadline::class, 'report_deadline_id');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(ClientReport::class, 'client_report_id');
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }
}
