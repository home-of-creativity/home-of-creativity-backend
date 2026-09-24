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
        'watermark_path',
        'header',
        'footer',
        'body',
        'document_path',
        'pdf_path',
        'drive_file_id',
        'drive_url',
        'drive_document_id',
        'drive_document_url',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ClientReportAttachment::class);
    }
}
