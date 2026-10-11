<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rotating app refresh token. Only the SHA-256 of the token is stored.
 * Every token from one sign-in shares a `family`.
 */
class AppRefreshToken extends Model
{
    protected $fillable = [
        'user_id',
        'family',
        'token_hash',
        'access_token_id',
        'device_name',
        'expires_at',
        'last_used_at',
        'revoked_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
