<?php

namespace Database\Factories;

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'platform' => SocialPlatform::Facebook,
            'name' => 'HOC Facebook',
            'handle' => 'homeofcreativity',
            'page_id' => '1234567890',
            'access_token' => null,
            'refresh_token' => null,
            'is_active' => true,
            'connection_status' => SocialAccountStatus::Pending,
            'connected_by' => User::factory(),
        ];
    }

    public function connected(): static
    {
        return $this->state(fn () => [
            'access_token' => 'test-page-token',
            'connection_status' => SocialAccountStatus::Connected,
            'last_error' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
