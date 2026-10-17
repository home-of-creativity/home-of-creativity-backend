<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotFeedback extends Model
{
    public const LOW = 3;

    public const SOURCE_SUPPORT_AGENT = 'support_agent';

    public const SOURCE_HUMAN_HOLD = 'human_hold';

    protected $table = 'bot_feedback';

    protected $fillable = [
        'client_id',
        'score',
        'reason',
        'source',
        'channel',
        'asked_at',
        'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'client_id' => 'integer',
            'score' => 'integer',
            'asked_at' => 'datetime',
            'answered_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function isLow(): bool
    {
        return $this->score !== null && $this->score <= self::LOW;
    }
}
