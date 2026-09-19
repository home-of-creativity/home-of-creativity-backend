<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ServiceRequest;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_twice_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::query()->where('email', 'test@example.com')->count());
        $this->assertSame(6, ServiceRequest::query()->where('number', 'like', 'REQ-%')->count());
    }

    /**
     * Regression test: production once threw
     * "Duplicate entry 'REQ-2026-000001' for key requests_number_unique"
     * on redeploy because the demo client (Client uses SoftDeletes) had
     * been soft-deleted, so the seeder's `firstOrCreate` on `user_id`
     * silently created a second, request-less client and then tried to
     * recreate the same fixed request numbers already owned by the first
     * (still present, just trashed) client.
     */
    public function test_seeding_after_demo_client_is_soft_deleted_does_not_duplicate_requests(): void
    {
        $this->seed(DatabaseSeeder::class);

        $client = Client::query()->where('email', 'test@example.com')->firstOrFail();
        $requestCountBefore = ServiceRequest::query()->count();
        $client->delete();
        $this->assertTrue($client->fresh()?->trashed());

        $this->seed(DatabaseSeeder::class);

        $restored = Client::query()->where('email', 'test@example.com')->firstOrFail();
        $this->assertFalse($restored->trashed());
        $this->assertSame($client->id, $restored->id);
        $this->assertSame($requestCountBefore, ServiceRequest::query()->count());
        $this->assertSame(6, ServiceRequest::query()->where('client_id', $restored->id)->count());
    }
}
