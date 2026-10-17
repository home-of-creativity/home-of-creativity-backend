<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A team note on a report, a social post, a calendar event, or a photography booking.
 */
class Comment extends Model
{
    /** @var list<string> */
    public const SUBJECTS = ['report', 'social_post', 'calendar_event', 'photography_booking', 'report_slot'];

    protected $fillable = [
        'subject_type',
        'subject_id',
        'subject_key',
        'calendar_id',
        'user_id',
        'parent_id',
        'body',
        'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
        ];
    }

    /** A short index key: Google event ids can be long. */
    public static function keyFor(string $subjectId, ?string $calendarId = null): string
    {
        return hash('sha256', ($calendarId ?? '').'|'.$subjectId);
    }

    /** @param  Builder<Comment>  $query */
    public function scopeOnSubject(Builder $query, string $type, string $id, ?string $calendarId = null): void
    {
        $query->where('subject_type', $type)->where('subject_key', self::keyFor($id, $calendarId));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
