<?php

namespace App\Services\AppAuth;

use App\Enums\AppRole;
use App\Models\AppRefreshToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Short-lived Sanctum access tokens plus rotating refresh tokens.
 *
 * A refresh token works once. Presenting an already rotated token means it
 * leaked, so every token of that sign-in (the family) is revoked.
 */
class AppTokenIssuer
{
    public const REFRESH_PREFIX = 'hocrt_';

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, refresh_expires_in: int}
     */
    public function issue(User $user, AppRole $role, ?string $deviceName = null, ?string $family = null): array
    {
        $accessMinutes = (int) config('auth.app.access_ttl_minutes');
        $refreshDays = (int) config('auth.app.refresh_ttl_days');
        $device = $this->deviceName($deviceName);

        $access = $user->createToken(
            'app:'.$device,
            ['app', $role->ability()],
            now()->addMinutes($accessMinutes),
        );

        $refresh = self::REFRESH_PREFIX.Str::random(64);
        AppRefreshToken::query()->create([
            'user_id' => $user->id,
            'family' => $family ?? (string) Str::uuid(),
            'token_hash' => self::hash($refresh),
            'access_token_id' => $access->accessToken->getKey(),
            'device_name' => $device,
            'expires_at' => now()->addDays($refreshDays),
        ]);

        return [
            'access_token' => $access->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => $accessMinutes * 60,
            'refresh_token' => $refresh,
            'refresh_expires_in' => $refreshDays * 86400,
        ];
    }

    /**
     * @return array{user: User, role: AppRole, tokens: array<string, mixed>}|null
     */
    public function rotate(string $refreshToken, AppAccountResolver $accounts): ?array
    {
        if (! str_starts_with($refreshToken, self::REFRESH_PREFIX)) {
            return null;
        }

        return DB::transaction(function () use ($refreshToken, $accounts): ?array {
            $row = AppRefreshToken::query()
                ->where('token_hash', self::hash($refreshToken))
                ->lockForUpdate()
                ->first();
            if (! $row) {
                return null;
            }

            if ($row->revoked_at !== null) {
                Log::warning('App refresh token reused. Revoking the family.', ['user' => $row->user_id]);
                $this->revokeFamily($row->family);

                return null;
            }

            if ($row->expires_at->isPast()) {
                $this->revokeRow($row);

                return null;
            }

            $user = $row->user;
            $role = $user ? $accounts->roleFor($user) : null;
            if (! $user || ! $role) {
                $this->revokeFamily($row->family);

                return null;
            }

            $this->revokeRow($row);
            $tokens = $this->issue($user, $role, $row->device_name, $row->family);

            return ['user' => $user, 'role' => $role, 'tokens' => $tokens];
        });
    }

    /**
     * Ends the current sign-in: the access token in use and, when it belongs
     * to the same user, the refresh family that was sent with it.
     */
    public function revoke(User $user, ?string $refreshToken = null): void
    {
        $current = $user->currentAccessToken();
        $family = null;

        if ($current instanceof PersonalAccessToken) {
            $family = AppRefreshToken::query()->where('access_token_id', $current->getKey())->value('family');
            $current->delete();
        }

        if (filled($refreshToken)) {
            $family ??= AppRefreshToken::query()
                ->where('user_id', $user->id)
                ->where('token_hash', self::hash((string) $refreshToken))
                ->value('family');
        }

        if ($family !== null) {
            $this->revokeFamily($family);
        }
    }

    public function revokeFamily(string $family): void
    {
        $rows = AppRefreshToken::query()->where('family', $family)->get();
        $tokenIds = $rows->pluck('access_token_id')->filter()->all();
        if ($tokenIds !== []) {
            PersonalAccessToken::query()->whereKey($tokenIds)->delete();
        }
        AppRefreshToken::query()
            ->where('family', $family)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function revokeRow(AppRefreshToken $row): void
    {
        if ($row->access_token_id !== null) {
            PersonalAccessToken::query()->whereKey($row->access_token_id)->delete();
        }
        $row->forceFill(['revoked_at' => now(), 'last_used_at' => now()])->save();
    }

    private function deviceName(?string $name): string
    {
        $name = trim(preg_replace('/[^\pL\pN ._\-()]+/u', '', (string) $name) ?? '');

        return $name === '' ? 'mobile' : Str::limit($name, 80, '');
    }
}
