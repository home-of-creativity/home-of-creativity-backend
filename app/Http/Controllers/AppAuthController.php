<?php

namespace App\Http\Controllers;

use App\Enums\AppRole;
use App\Http\Requests\App\AppOtpSendRequest;
use App\Http\Requests\App\AppOtpVerifyRequest;
use App\Http\Requests\App\AppRefreshRequest;
use App\Http\Requests\App\AppSocialLoginRequest;
use App\Http\Resources\AppUserResource;
use App\Models\User;
use App\Services\AppAuth\AppAccountResolver;
use App\Services\AppAuth\AppOtpService;
use App\Services\AppAuth\AppTokenIssuer;
use App\Services\AppAuth\FirebaseIdTokenVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Mobile app sign-in for clients, employees, and admins.
 *
 * Codes reach the account's bot chat. Google and Facebook go through
 * Firebase and only open an account that already exists.
 */
class AppAuthController extends Controller
{
    private const SOCIAL_PROVIDERS = ['google.com', 'facebook.com'];

    public function __construct(
        private AppAccountResolver $accounts,
        private AppTokenIssuer $tokens,
    ) {}

    public function sendOtp(AppOtpSendRequest $request, AppOtpService $otp): JsonResponse
    {
        $result = $otp->request($request->validated('phone'), $request->ip());

        if ($result['challenge'] === null) {
            return response()->json([
                'data' => ['retry_after' => $result['retry_after']],
                'message' => 'Please wait before asking for another code.',
            ], 429)->header('Retry-After', (string) $result['retry_after']);
        }

        return response()->json([
            'data' => [
                'challenge_id' => $result['challenge']->id,
                'expires_in' => (int) config('auth.app.otp_ttl_seconds'),
                'retry_after' => $result['retry_after'],
                'length' => (int) config('auth.app.otp_length'),
                'bot_url' => $this->clientBotUrl(),
            ],
            'message' => 'If this number has an account, a code was sent to its bot chat.',
        ]);
    }

    public function verifyOtp(AppOtpVerifyRequest $request, AppOtpService $otp): JsonResponse
    {
        $user = $otp->verify($request->validated('challenge_id'), $request->validated('code'));
        if (! $user) {
            return $this->fail('The code is wrong or expired.', 'invalid_code', 422);
        }

        return $this->signedIn($user, $request->validated('device_name'));
    }

    public function social(AppSocialLoginRequest $request, FirebaseIdTokenVerifier $verifier): JsonResponse
    {
        if (! $verifier->configured()) {
            return $this->fail('Social sign-in is not available yet.', 'social_unavailable', 503);
        }

        try {
            $claims = $verifier->verify($request->validated('id_token'));
        } catch (Throwable) {
            $claims = null;
        }
        if ($claims === null) {
            return $this->fail('The sign-in could not be verified.', 'invalid_token', 401);
        }

        $provider = $claims['firebase']['sign_in_provider'] ?? null;
        if (! in_array($provider, self::SOCIAL_PROVIDERS, true)) {
            return $this->fail('This sign-in method is not supported.', 'unsupported_provider', 422);
        }

        $uid = (string) $claims['sub'];
        $user = User::query()->where('firebase_uid', $uid)->first();

        if (! $user) {
            $email = is_string($claims['email'] ?? null) ? $claims['email'] : null;
            // Google proves the address. Facebook only returns a confirmed
            // primary email, and Firebase never marks it verified.
            $trusted = $provider === 'facebook.com' || ($claims['email_verified'] ?? false) === true;
            $user = ($email && $trusted) ? $this->accounts->forEmail($email) : null;

            if ($user && $user->firebase_uid === null) {
                $user->forceFill(['firebase_uid' => $uid])->save();
            }
        }

        if (! $user) {
            return $this->fail(
                'No Home of Creativity account uses this email. Sign in with your phone number.',
                'account_not_linked',
                404,
            );
        }

        return $this->signedIn($user, $request->validated('device_name'));
    }

    public function refresh(AppRefreshRequest $request): JsonResponse
    {
        $result = $this->tokens->rotate($request->validated('refresh_token'), $this->accounts);
        if ($result === null) {
            return $this->fail('Please sign in again.', 'invalid_refresh', 401);
        }

        return response()->json([
            'data' => [
                ...$result['tokens'],
                'user' => new AppUserResource($result['user'], $result['role']),
            ],
            'message' => 'ok',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $validated = $request->validate(['refresh_token' => ['nullable', 'string', 'max:200']]);
        $this->tokens->revoke($request->user(), $validated['refresh_token'] ?? null);

        return response()->json(['data' => null, 'message' => 'Signed out.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $this->accounts->roleFor($user);
        if (! $role) {
            return $this->fail('This account has no access to the app.', 'no_app_access', 403);
        }

        return response()->json([
            'data' => new AppUserResource($user, $role),
            'message' => 'ok',
        ]);
    }

    private function signedIn(User $user, ?string $deviceName): JsonResponse
    {
        $role = $this->accounts->roleFor($user);
        if (! $role instanceof AppRole) {
            return $this->fail('This account has no access to the app.', 'no_app_access', 403);
        }

        return response()->json([
            'data' => [
                ...$this->tokens->issue($user, $role, $deviceName),
                'user' => new AppUserResource($user, $role),
            ],
            'message' => 'ok',
        ]);
    }

    private function fail(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['data' => null, 'code' => $code, 'message' => $message], $status);
    }

    private function clientBotUrl(): ?string
    {
        $username = ltrim((string) config('services.telegram.bot_username'), '@');

        return $username === '' ? null : 'https://t.me/'.$username;
    }
}
