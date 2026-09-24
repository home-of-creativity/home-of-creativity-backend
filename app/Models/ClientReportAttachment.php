<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientReportAttachment extends Model
{
    protected $fillable = [
        'client_report_id',
        'original_name',
        'path',
        'size',
        'drive_file_id',
        'drive_url',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(ClientReport::class, 'client_report_id');
    }
}
