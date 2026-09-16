<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestFile extends Model
{
    protected $fillable = [
        'request_id',
        'kind',
        'original_name',
        'path',
        'drive_url',
        'drive_file_id',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'request_id');
    }
}
