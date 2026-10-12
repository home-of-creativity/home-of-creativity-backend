<?php

namespace App\Models;

use App\Enums\StaffAbility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = [
        'name',
        'abilities',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function allows(StaffAbility|string $ability): bool
    {
        $ability = $ability instanceof StaffAbility ? $ability->value : $ability;
        $granted = $this->abilities ?? [];

        if (in_array($ability, $granted, true)) {
            return true;
        }

        // A role that still holds the whole feature has each of its operations.
        $dot = strrpos($ability, '.');
        if ($dot === false) {
            return false;
        }
        $base = substr($ability, 0, $dot);

        return StaffAbility::actionsOf($base) !== [] && in_array($base, $granted, true);
    }

    public function includesSocial(): bool
    {
        foreach ($this->abilities ?? [] as $ability) {
            if (str_starts_with((string) $ability, 'social.')) {
                return true;
            }
        }

        return false;
    }
}
