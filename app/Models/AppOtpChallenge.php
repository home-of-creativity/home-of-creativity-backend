<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One app sign-in code. Only the HMAC of the code is stored. A challenge for
 * an unknown phone has no user, so it can never verify.
 */
class AppOtpChallenge extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'phone_hash',
        'code_hash',
        'channel',
        'attempts',
        'expires_at',
        'consumed_at',
        'ip',
    ];

    protected $hidden = ['code_hash', 'phone_hash'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOpen(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < (int) config('auth.app.otp_max_attempts');
    }
}
