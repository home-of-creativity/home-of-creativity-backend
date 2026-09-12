<?php

namespace Database\Factories;

use App\Enums\SocialPostStatus;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialPost>
 */
class SocialPostFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'body' => 'New campaign post from Home of Creativity.',
            'status' => SocialPostStatus::Draft,
            'scheduled_at' => null,
            'created_by' => User::factory(),
            'updated_by' => null,
            'approved_by' => null,
        ];
    }

    public function scheduled(): static
    {
        return $this->state(fn () => [
            'status' => SocialPostStatus::Scheduled,
            'scheduled_at' => now()->addHour(),
        ]);
    }

    public function approved(?User $user = null): static
    {
        return $this->state(fn () => [
            'approved_by' => $user?->id ?? User::factory(),
            'approved_at' => now(),
        ]);
    }
}
