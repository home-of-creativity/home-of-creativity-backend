<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_is_closed(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Ammar',
            'email' => 'ammar@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Accounts are created only through the Telegram bot.');
    }

    public function test_login_returns_token(): void
    {
        User::factory()->create([
            'email' => 'ammar@example.com',
            'password' => 'password',
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'ammar@example.com',
            'password' => 'password',
        ])->assertOk()->assertJsonStructure(['data' => ['token', 'user']])->assertCookie('hoc_staff');

        $this->call('GET', '/api/auth/me', [], ['hoc_staff' => (string) $login->json('data.token')], [], [
            'HTTP_ACCEPT' => 'application/json',
        ])->assertOk()->assertJsonPath('data.email', 'ammar@example.com');
    }

    public function test_login_rejects_bad_password(): void
    {
        User::factory()->create([
            'email' => 'ammar@example.com',
            'password' => 'password',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'ammar@example.com',
            'password' => 'wrong-pass',
        ])->assertUnprocessable();
    }

    public function test_me_requires_token(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }
}
