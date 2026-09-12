<?php

namespace App\Models;

use App\Enums\SocialAbility;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'telegram_user_id',
        'locale',
        'password',
        'social_permissions',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'social_permissions' => 'array',
        ];
    }

    public function client(): HasOne
    {
        return $this->hasOne(Client::class);
    }

    public function canSocial(SocialAbility|string $ability): bool
    {
        if (! $this->is_admin) {
            return false;
        }

        $ability = $ability instanceof SocialAbility ? $ability->value : $ability;

        if ($this->social_permissions === null) {
            return true;
        }

        return in_array($ability, $this->social_permissions, true);
    }

    /**
     * @return list<string>
     */
    public function socialAbilities(): array
    {
        if (! $this->is_admin) {
            return [];
        }

        $all = SocialAbility::values();

        if ($this->social_permissions === null) {
            return $all;
        }

        return array_values(array_intersect($all, $this->social_permissions));
    }
}
