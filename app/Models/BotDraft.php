<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotDraft extends Model
{
    public const KEEP_DAYS = 14;

    public const MAX_BYTES = 65536;

    protected $fillable = [
        'bot',
        'telegram_user_id',
        'payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
