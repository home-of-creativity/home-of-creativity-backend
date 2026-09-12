<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialInboxReply extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'social_inbox_item_id',
        'user_id',
        'body',
        'external_id',
        'sent_at',
        'last_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(SocialInboxItem::class, 'social_inbox_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
