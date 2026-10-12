<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Complaint extends Model
{
    public const OPEN = 'open';

    /** A ticket is open and the team is working on it. */
    public const IN_PROGRESS = 'in_progress';

    public const RESOLVED = 'resolved';

    public const STATUSES = [self::OPEN, self::IN_PROGRESS, self::RESOLVED];

    protected $fillable = [
        'client_id',
        'ticket_number',
        'chat_id',
        'ticket_opened_at',
        'title',
        'description',
        'image_path',
        'image_mime',
        'audio_path',
        'audio_mime',
        'status',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'ticket_opened_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ComplaintMessage::class);
    }
}
