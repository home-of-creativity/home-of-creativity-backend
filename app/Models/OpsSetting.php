<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpsSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    public static function getValue(string $key, ?string $default = null): ?string
    {
        $row = static::query()->where('key', $key)->first();

        if (! $row || $row->value === null || $row->value === '') {
            return $default;
        }

        return (string) $row->value;
    }

    public static function setValue(string $key, ?string $value): self
    {
        return static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value],
        );
    }
}
