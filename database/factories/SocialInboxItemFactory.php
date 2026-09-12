<?php

namespace Database\Factories;

use App\Enums\SocialInboxKind;
use App\Models\SocialAccount;
use App\Models\SocialInboxItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialInboxItem>
 */
class SocialInboxItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'social_account_id' => SocialAccount::factory(),
            'kind' => SocialInboxKind::Comment,
            'external_id' => fake()->unique()->numerify('cmt_######'),
            'author_name' => fake()->name(),
            'author_handle' => fake()->userName(),
            'body' => 'Can you share more details?',
            'occurred_at' => now()->subHour(),
            'is_replied' => false,
        ];
    }

    public function message(): static
    {
        return $this->state(fn () => [
            'kind' => SocialInboxKind::Message,
            'external_id' => fake()->unique()->numerify('msg_######'),
        ]);
    }
}
