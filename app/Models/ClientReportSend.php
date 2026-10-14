<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One delivery of a report PDF to a client, by email or WhatsApp. */
class ClientReportSend extends Model
{
    protected $fillable = [
        'client_report_id',
        'user_id',
        'channel',
        'recipient',
        'message',
        'delivered',
    ];

    protected function casts(): array
    {
        return [
            'delivered' => 'boolean',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(ClientReport::class, 'client_report_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
