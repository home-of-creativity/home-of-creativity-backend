<?php

namespace App\Models;

use App\Enums\SocialAbility;
use App\Enums\StaffAbility;
use App\Services\SocialPageAccess;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'role_id',
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

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function pageGrants(): HasMany
    {
        return $this->hasMany(StaffPageGrant::class);
    }

    public function canEnterDashboard(): bool
    {
        return $this->is_admin || $this->role_id !== null;
    }

    public function seesAllSocialPages(): bool
    {
        return $this->is_admin && $this->role_id === null;
    }

    public function canAbility(StaffAbility|string $ability): bool
    {
        $ability = $ability instanceof StaffAbility ? $ability->value : $ability;

        if ($this->role_id !== null) {
            $this->loadMissing('role');

            return $this->role?->allows($ability) ?? false;
        }

        if (! $this->is_admin) {
            return false;
        }

        if (! str_starts_with($ability, 'social.')) {
            return true;
        }

        return in_array($ability, StaffAbility::fromLegacySocial($this->social_permissions), true);
    }

    /**
     * @return list<string>
     */
    public function abilities(): array
    {
        return array_values(array_filter(
            StaffAbility::values(),
            fn (string $ability): bool => $this->canAbility($ability),
        ));
    }

    public function canSocial(SocialAbility|string $ability): bool
    {
        $ability = $ability instanceof SocialAbility ? $ability->value : $ability;

        $mapped = match ($ability) {
            SocialAbility::Accounts->value => StaffAbility::SocialAccounts,
            SocialAbility::Create->value => StaffAbility::SocialContent,
            SocialAbility::Approve->value => StaffAbility::SocialApprove,
            SocialAbility::Engage->value => StaffAbility::SocialEngage,
            default => $ability,
        };

        return $this->canAbility($mapped);
    }

    /**
     * @return list<string>
     */
    public function socialAbilities(): array
    {
        $legacy = [];
        if ($this->canAbility(StaffAbility::SocialAccounts)) {
            $legacy[] = SocialAbility::Accounts->value;
        }
        if ($this->canAbility(StaffAbility::SocialContent)) {
            $legacy[] = SocialAbility::Create->value;
        }
        if ($this->canAbility(StaffAbility::SocialApprove)) {
            $legacy[] = SocialAbility::Approve->value;
        }
        if ($this->canAbility(StaffAbility::SocialEngage) || $this->canAbility(StaffAbility::SocialMessages)) {
            $legacy[] = SocialAbility::Engage->value;
        }

        return $legacy;
    }

    public function canAccessSocialAccount(int $accountId, ?string $ability = null): bool
    {
        return app(SocialPageAccess::class)->allowsAccount(
            $this,
            $accountId,
            $ability === null ? null : [$ability],
        );
    }
}
