<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A calendar on the reports calendar page. Seeing it is a role ability of its own,
 * `ops.report_calendar.{id}`, listed on the permissions page beside the fixed ones.
 */
class ReportCalendar extends Model
{
    public const ABILITY_PREFIX = 'ops.report_calendar.';

    protected $fillable = [
        'name',
        'color',
        'created_by',
    ];

    public function deadlines(): HasMany
    {
        return $this->hasMany(ReportDeadline::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function abilityKey(): string
    {
        return self::ABILITY_PREFIX.$this->id;
    }

    public static function keyFor(int $id): string
    {
        return self::ABILITY_PREFIX.$id;
    }

    /**
     * Every calendar view ability that a role may hold right now.
     *
     * @return list<string>
     */
    public static function abilityKeys(): array
    {
        return static::query()->orderBy('id')->pluck('id')
            ->map(fn (int $id): string => self::keyFor($id))
            ->all();
    }
}
