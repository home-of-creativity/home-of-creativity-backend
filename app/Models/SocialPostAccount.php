<?php

namespace App\Models;

use App\Enums\SocialPublishStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class SocialPostAccount extends Pivot
{
    protected $table = 'social_post_accounts';

    public $incrementing = true;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'social_post_id',
        'social_account_id',
        'status',
        'external_id',
        'published_at',
        'last_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SocialPublishStatus::class,
            'published_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }
}
