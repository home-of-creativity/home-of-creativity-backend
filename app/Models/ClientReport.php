<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientReport extends Model
{
    protected $fillable = [
        'client_id',
        'title',
        'cover_path',
        'header',
        'footer',
        'body',
        'drive_file_id',
        'drive_url',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ClientReportAttachment::class);
    }
}
