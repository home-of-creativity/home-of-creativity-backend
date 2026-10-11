<?php

namespace App\Services\AppAuth;

use App\Models\AppOtpChallenge;
use App\Models\User;
use App\Services\TelegramNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * App sign-in codes delivered on the account's bot chat.
 *
 * `request()` answers the same way for a known and an unknown phone, so the
 * endpoint cannot be used to discover which numbers have an account.
 */
class AppOtpService
{
    public function __construct(
        private AppAccountResolver $accounts,
        private TelegramNotifier $telegram,
    ) {}

    /**
     * @return array{challenge: AppOtpChallenge, retry_after: int}|array{challenge: null, retry_after: int}
     */
    public function request(string $phone, ?string $ip = null): array
    {
        $phoneHash = $this->phoneHash($phone);
        $resend = (int) config('auth.app.otp_resend_seconds');

        $latest = AppOtpChallenge::query()
            ->where('phone_hash', $phoneHash)
            ->latest('created_at')
            ->first();
        if ($latest && $latest->created_at->diffInSeconds(now()) < $resend) {
            return [
                'challenge' => null,
                'retry_after' => max(1, $resend - (int) $latest->created_at->diffInSeconds(now())),
            ];
        }

        $hourKey = 'app-otp-phone:'.$phoneHash;
        if (RateLimiter::tooManyAttempts($hourKey, (int) config('auth.app.otp_per_phone_per_hour'))) {
            return ['challenge' => null, 'retry_after' => RateLimiter::availableIn($hourKey)];
        }
        RateLimiter::hit($hourKey, 3600);

        $target = $this->accounts->forPhone($phone);
        $code = $this->newCode();

        $challenge = new AppOtpChallenge([
            'user_id' => $target?->user->id,
            'phone_hash' => $phoneHash,
            'channel' => $target === null ? null : ($target->bot === 'staff' ? 'telegram_staff' : $this->clientChannel($target->chatId)),
            'attempts' => 0,
            'expires_at' => now()->addSeconds((int) config('auth.app.otp_ttl_seconds')),
            'ip' => $ip,
        ]);
        $challenge->id = (string) $challenge->newUniqueId();
        $challenge->code_hash = $this->codeHash($challenge->id, $code);
        $challenge->save();

        if ($target !== null) {
            $this->deliver($target, $code);
        }

        return ['challenge' => $challenge, 'retry_after' => $resend];
    }

    /**
     * The signed-in user, or null for a wrong, expired, used, or locked code.
     */
    public function verify(string $challengeId, string $code): ?User
    {
        return DB::transaction(function () use ($challengeId, $code): ?User {
            $challenge = AppOtpChallenge::query()->lockForUpdate()->find($challengeId);
            if (! $challenge || ! $challenge->isOpen()) {
                return null;
            }

            $challenge->increment('attempts');

            $matches = hash_equals($challenge->code_hash, $this->codeHash($challenge->id, trim($code)));
            if (! $matches || $challenge->user_id === null) {
                return null;
            }

            $challenge->forceFill(['consumed_at' => now()])->save();

            return $challenge->user;
        });
    }

    private function deliver(AppOtpTarget $target, string $code): void
    {
        $minutes = (int) ceil(((int) config('auth.app.otp_ttl_seconds')) / 60);
        $text = $target->locale === 'en'
            ? "Your Home of Creativity app code: {$code}\nIt expires in {$minutes} minutes. Do not share it with anyone, including our team."
            : "رمز الدخول إلى تطبيق Home of Creativity: {$code}\nصالح لمدة {$minutes} دقائق. لا تشاركه مع أي شخص، حتى فريقنا.";

        try {
            $this->telegram->send($target->chatId, $text, $target->bot, transactional: true);
        } catch (Throwable $exception) {
            Log::warning('App OTP delivery failed.', [
                'user' => $target->user->id,
                'bot' => $target->bot,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function clientChannel(string $chatId): string
    {
        return str_starts_with($chatId, 'wa:') ? 'whatsapp' : 'telegram';
    }

    private function newCode(): string
    {
        $length = (int) config('auth.app.otp_length');

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    private function phoneHash(string $phone): string
    {
        $digits = AppAccountResolver::digits($phone);

        return hash_hmac('sha256', substr($digits, -8) ?: $digits, (string) config('app.key'));
    }

    private function codeHash(string $challengeId, string $code): string
    {
        return hash_hmac('sha256', $challengeId.'|'.$code, (string) config('app.key'));
    }
}
