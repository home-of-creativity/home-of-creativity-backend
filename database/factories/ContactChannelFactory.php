<?php

namespace Database\Factories;

use App\Models\ContactChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactChannel>
 */
class ContactChannelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => 'mobile',
            'region' => 'SYR',
            'platform' => null,
            'value' => '+963 968 862 822',
            'value_ar' => null,
            'digits' => '963968862822',
            'url' => null,
            'sort_order' => 1,
            'is_published' => true,
        ];
    }

    public function whatsapp(): static
    {
        return $this->state(fn () => [
            'kind' => 'whatsapp',
            'value' => '+963 954 187 154',
            'digits' => '963954187154',
        ]);
    }

    public function social(string $platform = 'instagram'): static
    {
        return $this->state(fn () => [
            'kind' => 'social',
            'region' => null,
            'platform' => $platform,
            'value' => $platform,
            'digits' => null,
            'url' => 'https://www.instagram.com/homeofcreativity/',
        ]);
    }

    public function location(): static
    {
        return $this->state(fn () => [
            'kind' => 'location',
            'platform' => null,
            'value' => 'Damascus, Al Hamra',
            'value_ar' => 'دمشق، الحمراء',
            'digits' => null,
            'url' => null,
        ]);
    }

    public function unpublished(): static
    {
        return $this->state(fn () => ['is_published' => false]);
    }
}
