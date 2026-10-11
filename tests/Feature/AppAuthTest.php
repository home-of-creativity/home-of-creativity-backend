<?php

namespace Tests\Feature;

use App\Models\AppOtpChallenge;
use App\Models\AppRefreshToken;
use App\Models\Client;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AppAuthTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = 'hoc-test-project';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'client-token',
            'services.telegram.staff_bot_token' => 'staff-token',
            'services.telegram.bot_username' => 'hoc_client_bot',
            'services.firebase.project_id' => self::PROJECT,
        ]);
        Cache::flush();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    }

    public function test_client_receives_the_code_on_the_client_bot_and_signs_in(): void
    {
        $client = Client::factory()->create([
            'user_id' => null,
            'phone' => '+963 944 123 456',
            'telegram_user_id' => '700100',
            'company_name' => 'Acme',
        ]);

        $send = $this->postJson('/api/app/auth/otp/send', ['phone' => '0944123456'])
            ->assertOk()
            ->assertJsonPath('data.length', 6)
            ->assertJsonPath('data.bot_url', 'https://t.me/hoc_client_bot');

        $code = $this->sentCode('client-token', '700100');
        $this->assertNotSame('', $code);
        $this->assertDatabaseMissing('app_otp_challenges', ['code_hash' => $code]);

        $response = $this->postJson('/api/app/auth/otp/verify', [
            'challenge_id' => $send->json('data.challenge_id'),
            'code' => $code,
            'device_name' => 'Pixel 9',
        ])->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.expires_in', 30 * 60)
            ->assertJsonPath('data.user.role', 'client')
            ->assertJsonPath('data.user.company_name', 'Acme');

        $this->assertStringStartsWith('hocrt_', $response->json('data.refresh_token'));
        $client->refresh();
        $this->assertNotNull($client->user_id);

        $token = PersonalAccessToken::findToken($response->json('data.access_token'));
        $this->assertNotNull($token?->expires_at);
        $this->assertTrue($token->can('app'));
        $this->assertTrue($token->can('role:client'));

        $this->withToken($response->json('data.access_token'))
            ->getJson('/api/app/auth/me')
            ->assertOk()
            ->assertJsonPath('data.role', 'client');
    }

    public function test_employee_receives_the_code_on_the_staff_bot(): void
    {
        Employee::factory()->create([
            'phone' => '+963 955 000 111',
            'telegram_user_id' => '800200',
        ]);

        $send = $this->postJson('/api/app/auth/otp/send', ['phone' => '+963955000111'])->assertOk();
        $code = $this->sentCode('staff-token', '800200');

        $this->postJson('/api/app/auth/otp/verify', [
            'challenge_id' => $send->json('data.challenge_id'),
            'code' => $code,
        ])->assertOk()->assertJsonPath('data.user.role', 'employee');
    }

    public function test_admin_employee_opens_the_admin_side(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Employee::factory()->create([
            'user_id' => $admin->id,
            'phone' => '+963 966 777 888',
            'telegram_user_id' => '900300',
        ]);

        $send = $this->postJson('/api/app/auth/otp/send', ['phone' => '0966777888'])->assertOk();

        $this->postJson('/api/app/auth/otp/verify', [
            'challenge_id' => $send->json('data.challenge_id'),
            'code' => $this->sentCode('staff-token', '900300'),
        ])->assertOk()->assertJsonPath('data.user.role', 'admin');
    }

    public function test_unknown_phone_gets_the_same_answer_and_never_verifies(): void
    {
        $send = $this->postJson('/api/app/auth/otp/send', ['phone' => '+44 7700 900123'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['challenge_id', 'expires_in', 'retry_after', 'length']]);

        Http::assertNothingSent();

        $this->postJson('/api/app/auth/otp/verify', [
            'challenge_id' => $send->json('data.challenge_id'),
            'code' => '123456',
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_code');
    }

    public function test_resend_waits_for_the_cooldown(): void
    {
        $this->postJson('/api/app/auth/otp/send', ['phone' => '0944000999'])->assertOk();

        $this->postJson('/api/app/auth/otp/send', ['phone' => '0944000999'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_code_locks_after_too_many_wrong_attempts(): void
    {
        Client::factory()->create(['phone' => '0933111222', 'telegram_user_id' => '1111']);
        $send = $this->postJson('/api/app/auth/otp/send', ['phone' => '0933111222'])->assertOk();
        $code = $this->sentCode('client-token', '1111');
        $wrong = $code === '000000' ? '111111' : '000000';

        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/app/auth/otp/verify', [
                'challenge_id' => $send->json('data.challenge_id'),
                'code' => $wrong,
            ])->assertStatus(422);
        }

        $this->postJson('/api/app/auth/otp/verify', [
            'challenge_id' => $send->json('data.challenge_id'),
            'code' => $code,
        ])->assertStatus(422);
    }

    public function test_code_works_once_and_expires(): void
    {
        Client::factory()->create(['phone' => '0933222333', 'telegram_user_id' => '2222']);
        $send = $this->postJson('/api/app/auth/otp/send', ['phone' => '0933222333'])->assertOk();
        $code = $this->sentCode('client-token', '2222');
        $payload = ['challenge_id' => $send->json('data.challenge_id'), 'code' => $code];

        $this->postJson('/api/app/auth/otp/verify', $payload)->assertOk();
        $this->postJson('/api/app/auth/otp/verify', $payload)->assertStatus(422);

        AppOtpChallenge::query()->update(['consumed_at' => null, 'expires_at' => now()->subSecond()]);
        $this->postJson('/api/app/auth/otp/verify', $payload)->assertStatus(422);
    }

    public function test_refresh_rotates_and_reuse_revokes_the_family(): void
    {
        $tokens = $this->signInClient('0933444555', '3333');

        $rotated = $this->postJson('/api/app/auth/refresh', ['refresh_token' => $tokens['refresh_token']])
            ->assertOk()
            ->json('data');
        $this->assertNotSame($tokens['refresh_token'], $rotated['refresh_token']);
        $this->assertNull(PersonalAccessToken::findToken($tokens['access_token']));

        // The old refresh token is replayed: everything from this sign-in dies.
        $this->postJson('/api/app/auth/refresh', ['refresh_token' => $tokens['refresh_token']])
            ->assertStatus(401)
            ->assertJsonPath('code', 'invalid_refresh');

        $this->assertNull(PersonalAccessToken::findToken($rotated['access_token']));
        $this->postJson('/api/app/auth/refresh', ['refresh_token' => $rotated['refresh_token']])->assertStatus(401);
    }

    public function test_expired_access_token_is_rejected(): void
    {
        $tokens = $this->signInClient('0933666777', '4444');
        PersonalAccessToken::query()->update(['expires_at' => now()->subMinute()]);

        $this->withToken($tokens['access_token'])->getJson('/api/app/auth/me')->assertUnauthorized();
    }

    public function test_logout_revokes_access_and_refresh(): void
    {
        $tokens = $this->signInClient('0933888999', '5555');

        $this->withToken($tokens['access_token'])
            ->postJson('/api/app/auth/logout', ['refresh_token' => $tokens['refresh_token']])
            ->assertOk();

        $this->assertNull(PersonalAccessToken::findToken($tokens['access_token']));
        $this->assertSame(0, AppRefreshToken::query()->whereNull('revoked_at')->count());
        $this->postJson('/api/app/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertStatus(401);
    }

    public function test_google_sign_in_opens_the_client_with_that_email(): void
    {
        $client = Client::factory()->create(['user_id' => null, 'email' => 'owner@acme.test']);
        $idToken = $this->firebaseToken(['email' => 'owner@acme.test', 'email_verified' => true]);

        $this->postJson('/api/app/auth/social', ['id_token' => $idToken])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'client')
            ->assertJsonPath('data.user.email', 'owner@acme.test');

        $this->assertSame('firebase-uid-1', $client->fresh()->user->firebase_uid);
    }

    public function test_unverified_google_email_is_not_trusted(): void
    {
        Client::factory()->create(['email' => 'owner@acme.test']);
        $idToken = $this->firebaseToken(['email' => 'owner@acme.test', 'email_verified' => false]);

        $this->postJson('/api/app/auth/social', ['id_token' => $idToken])
            ->assertNotFound()
            ->assertJsonPath('code', 'account_not_linked');
    }

    public function test_facebook_sign_in_matches_an_employee_email(): void
    {
        Employee::factory()->create(['email' => 'designer@hoc.test']);
        $idToken = $this->firebaseToken(
            ['email' => 'designer@hoc.test', 'email_verified' => false],
            provider: 'facebook.com',
        );

        $this->postJson('/api/app/auth/social', ['id_token' => $idToken])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'employee');
    }

    public function test_social_token_for_another_project_or_tampered_is_rejected(): void
    {
        Client::factory()->create(['email' => 'owner@acme.test']);

        $other = $this->firebaseToken(['email' => 'owner@acme.test', 'email_verified' => true], project: 'someone-else');
        $this->postJson('/api/app/auth/social', ['id_token' => $other])->assertUnauthorized();

        $valid = $this->firebaseToken(['email' => 'owner@acme.test', 'email_verified' => true]);
        [$header, , $signature] = explode('.', $valid);
        $forged = $this->base64Url(json_encode([
            'aud' => self::PROJECT,
            'iss' => 'https://securetoken.google.com/'.self::PROJECT,
            'sub' => 'attacker',
            'iat' => time(),
            'exp' => time() + 3600,
            'email' => 'owner@acme.test',
            'email_verified' => true,
            'firebase' => ['sign_in_provider' => 'google.com'],
        ]));
        $this->postJson('/api/app/auth/social', ['id_token' => "{$header}.{$forged}.{$signature}"])->assertUnauthorized();
    }

    public function test_dashboard_token_cannot_be_used_from_an_app_role_less_account(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $token = $user->createToken('api')->plainTextToken;

        $this->withToken($token)->getJson('/api/app/auth/me')->assertForbidden();
    }

    /**
     * @return array{access_token: string, refresh_token: string}
     */
    private function signInClient(string $phone, string $chatId): array
    {
        Client::factory()->create(['phone' => $phone, 'telegram_user_id' => $chatId]);
        $send = $this->postJson('/api/app/auth/otp/send', ['phone' => $phone])->assertOk();

        return $this->postJson('/api/app/auth/otp/verify', [
            'challenge_id' => $send->json('data.challenge_id'),
            'code' => $this->sentCode('client-token', $chatId),
        ])->assertOk()->json('data');
    }

    private function sentCode(string $botToken, string $chatId): string
    {
        $code = '';
        Http::assertSent(function (Request $request) use ($botToken, $chatId, &$code): bool {
            if (! str_contains($request->url(), "/bot{$botToken}/sendMessage") || (string) $request['chat_id'] !== $chatId) {
                return false;
            }
            preg_match('/\b(\d{6})\b/', (string) $request['text'], $match);
            $code = $match[1] ?? '';

            return $code !== '';
        });

        return $code;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function firebaseToken(array $claims, string $provider = 'google.com', string $project = self::PROJECT): string
    {
        static $key = null;
        static $cert = null;
        if ($key === null) {
            // XAMPP on Windows ships openssl.cnf beside PHP instead of a system default.
            $cnf = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
            $options = ['digest_alg' => 'sha256', ...(is_file($cnf) ? ['config' => $cnf] : [])];
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, ...$options]);
            $csr = openssl_csr_new(['commonName' => 'securetoken'], $key, $options);
            openssl_x509_export(openssl_csr_sign($csr, null, $key, 1, $options), $cert);
        }

        Http::fake(['www.googleapis.com/*' => Http::response(['kid-1' => $cert], 200, ['Cache-Control' => 'public, max-age=600'])]);

        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'kid' => 'kid-1', 'typ' => 'JWT']));
        $payload = $this->base64Url(json_encode([
            'aud' => $project,
            'iss' => 'https://securetoken.google.com/'.$project,
            'sub' => 'firebase-uid-1',
            'iat' => time() - 10,
            'exp' => time() + 3600,
            'auth_time' => time() - 10,
            'firebase' => ['sign_in_provider' => $provider],
            ...$claims,
        ]));
        openssl_sign("{$header}.{$payload}", $signature, $key, OPENSSL_ALGO_SHA256);

        return "{$header}.{$payload}.".$this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
