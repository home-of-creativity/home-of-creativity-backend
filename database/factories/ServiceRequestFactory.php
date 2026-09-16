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
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'status' => RequestStatus::Submitted,
            'source' => RequestSource::Website,
        ];
    }
}
