<?php

namespace App\Models;

use App\Enums\SocialInboxKind;
use Database\Factories\SocialInboxItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class SocialInboxItem extends Model
{
    /** @use HasFactory<SocialInboxItemFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'social_account_id',
        'kind',
        'external_id',
        'source_external_id',
        'author_name',
        'author_handle',
        'body',
        'occurred_at',
        'is_replied',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => SocialInboxKind::class,
            'occurred_at' => 'datetime',
            'is_replied' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(SocialInboxReply::class);
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(SocialActivity::class, 'subject')->latest();
    }

    public static function deleteCommentsForSource(int $accountId, string $sourceId): int
    {
        return static::query()
            ->where('social_account_id', $accountId)
            ->where('kind', SocialInboxKind::Comment)
            ->where(function ($query) use ($sourceId): void {
                $query->where('source_external_id', $sourceId)
                    ->orWhere('external_id', 'like', $sourceId.'_%');
            })
            ->delete();
    }
}
