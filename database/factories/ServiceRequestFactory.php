<?php

namespace Database\Factories;

use App\Enums\RequestSource;
use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\ServiceRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceRequest>
 */
class ServiceRequestFactory extends Factory
{
    protected $model = ServiceRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $sequence = 1;

        return [
            'number' => sprintf('REQ-%d-%06d', now()->year, $sequence++),
            'client_id' => Client::factory(),
            'title' => \fake()->sentence(4),
            'description' => \fake()->paragraph(),
            'status' => RequestStatus::Submitted,
            'source' => RequestSource::Website,
        ];
    }

    /** Paid, in progress, with photography sessions on the request. */
    public function photography(int $sessions = 2): static
    {
        return $this->state(fn (): array => [
            'status' => RequestStatus::InProgress,
            'paid_at' => now(),
            'photography_sessions' => $sessions,
            'photography_sessions_used' => 0,
            'photography_period_key' => 'initial',
        ]);
    }
}
