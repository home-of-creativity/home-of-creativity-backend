<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppOutboxMessage extends Model
{
    protected $table = 'whatsapp_outbox_messages';

    protected $fillable = [
        'recipient',
        'kind',
        'body',
        'buttons',
        'file_path',
        'filename',
    ];

    protected function casts(): array
    {
        return [
            'buttons' => 'array',
        ];
    }
}
