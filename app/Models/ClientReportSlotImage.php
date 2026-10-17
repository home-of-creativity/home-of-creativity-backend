<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientReportSlotImage extends Model
{
    protected $fillable = [
        'client_report_slot_id',
        'path',
        'name',
        'position',
    ];

    public function slot(): BelongsTo
    {
        return $this->belongsTo(ClientReportSlot::class, 'client_report_slot_id');
    }
}
