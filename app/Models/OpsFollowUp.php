<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\UniqueConstraintViolationException;

class OpsFollowUp extends Model
{
    public const KIND_QUOTE_WAITING = 'quote_waiting';

    public const KIND_RECEIPT_WAITING = 'receipt_waiting';

    public const KIND_RECEIPT_WAITING_SALES = 'receipt_waiting_sales';

    public const KIND_RECEIPT_UNCONFIRMED = 'receipt_unconfirmed';

    public const KIND_EXECUTION_STARTED = 'execution_started';

    public const KIND_REVISION_STALE = 'revision_stale';

    public const KIND_SUPPORT_STALE = 'support_stale';

    public const KIND_PROFILE_INCOMPLETE = 'profile_incomplete';

    public const KIND_SALES_DIGEST = 'sales_digest';

    public const KIND_TELEGRAM_FAIL = 'telegram_fail';

    protected $fillable = [
        'kind',
        'dedupe_key',
        'subject_type',
        'subject_id',
        'sent_at',
        'send_count',
        'meta',
    ];

    protected $attributes = [
        'send_count' => 1,
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'send_count' => 'integer',
            'meta' => 'array',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function claim(string $kind, string $dedupeKey, ?Model $subject = null, array $meta = []): bool
    {
        if (static::query()->where('kind', $kind)->where('dedupe_key', $dedupeKey)->exists()) {
            return false;
        }

        try {
            static::query()->create([
                'kind' => $kind,
                'dedupe_key' => $dedupeKey,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'sent_at' => now(),
                'send_count' => 1,
                'meta' => $meta === [] ? null : $meta,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
