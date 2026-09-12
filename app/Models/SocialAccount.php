<?php

namespace App\Models;

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use Database\Factories\SocialAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialAccount extends Model
{
    /** @use HasFactory<SocialAccountFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'platform',
        'name',
        'handle',
        'page_id',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'is_active',
        'connection_status',
        'last_error',
        'connected_by',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => SocialPlatform::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'is_active' => 'boolean',
            'connection_status' => SocialAccountStatus::class,
        ];
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(SocialPost::class, 'social_post_accounts')
            ->using(SocialPostAccount::class)
            ->withPivot(['status', 'external_id', 'published_at', 'last_error'])
            ->withTimestamps();
    }

    public function inboxItems(): HasMany
    {
        return $this->hasMany(SocialInboxItem::class);
    }

    public function hasToken(): bool
    {
        return filled($this->access_token);
    }

    /**
     * @param  Builder<SocialAccount>  $query
     * @return Builder<SocialAccount>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
