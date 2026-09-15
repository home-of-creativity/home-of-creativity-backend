<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurgeE2eDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_e2e_purge_command_removes_playwright_records(): void
    {
        $e2eClient = Client::factory()->create([
            'name' => 'E2E Telegram Client',
            'telegram_user_id' => 'tg-e2e-123',
        ]);
        ServiceRequest::factory()->for($e2eClient)->create(['title' => 'E2E booth identity']);

        $demoUser = User::factory()->create(['email' => 'test@example.com']);
        $demoClient = Client::factory()->for($demoUser)->create();
        ServiceRequest::factory()->for($demoClient)->create(['title' => 'Demo seeded request']);

        $realClient = Client::factory()->create(['name' => 'Real Client']);
        ServiceRequest::factory()->for($realClient)->create(['title' => 'Brand refresh']);

        $this->artisan('e2e:purge')
            ->assertSuccessful();

        $this->assertDatabaseMissing('clients', ['id' => $e2eClient->id]);
        $this->assertDatabaseMissing('requests', ['title' => 'E2E booth identity']);
        $this->assertDatabaseMissing('requests', ['title' => 'Demo seeded request']);
        $this->assertDatabaseHas('requests', ['title' => 'Brand refresh']);
        $this->assertDatabaseHas('clients', ['name' => 'Real Client']);
    }
}
