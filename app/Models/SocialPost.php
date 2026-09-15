<?php

namespace App\Models;

use App\Enums\SocialPlacement;
use App\Enums\SocialPostStatus;
use Database\Factories\SocialPostFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class SocialPost extends Model
{
    /** @use HasFactory<SocialPostFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'placement' => 'feed',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'body',
        'placement',
        'status',
        'scheduled_at',
        'published_at',
        'approved_at',
        'last_error',
        'created_by',
        'updated_by',
        'approved_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'placement' => SocialPlacement::class,
            'status' => SocialPostStatus::class,
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(SocialAccount::class, 'social_post_accounts')
            ->using(SocialPostAccount::class)
            ->withPivot(['id', 'status', 'external_id', 'published_at', 'last_error'])
            ->withTimestamps();
    }

    public function targets(): HasMany
    {
        return $this->hasMany(SocialPostAccount::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(SocialPostMedia::class)->orderBy('sort_order')->orderBy('id');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(SocialActivity::class, 'subject')->latest();
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function isDeletable(): bool
    {
        return $this->status->isDeletable();
    }

    /**
     * @param  Builder<SocialPost>  $query
     * @return Builder<SocialPost>
     */
    public function scopeDueForPublish(Builder $query): Builder
    {
        return $query
            ->where('status', SocialPostStatus::Scheduled)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now());
    }
}
