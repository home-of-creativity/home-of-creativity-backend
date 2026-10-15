<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\StagingGuard;
use Database\Seeders\StagingSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class StagingEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_staging_refuses_any_database_but_the_testing_one(): void
    {
        StagingGuard::assertDatabase('staging', 'hoc_testing');
        StagingGuard::assertDatabase('production', 'hoc_platform');

        $this->assertGuardFails('staging', 'hoc_platform');
        $this->assertGuardFails('staging', '');
        $this->assertGuardFails('production', 'hoc_testing');
    }

    public function test_the_tester_command_refuses_outside_staging(): void
    {
        $this->artisan('staging:tester', ['email' => 'qa@example.com', '--generate' => true])
            ->expectsOutputToContain('Refused')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'qa@example.com']);
    }

    public function test_the_tester_command_adds_a_full_admin_on_staging(): void
    {
        $this->actAsStaging();

        $this->artisan('staging:tester', ['email' => 'QA@Example.com', '--generate' => true, '--name' => 'QA'])
            ->expectsOutputToContain('Tester ready: qa@example.com')
            ->expectsOutputToContain('Password (shown once)')
            ->assertSuccessful();

        $user = User::query()->where('email', 'qa@example.com')->firstOrFail();
        $this->assertTrue($user->is_admin);
        $this->assertNull($user->role_id);
        $this->assertSame('QA', $user->name);
    }

    public function test_a_typed_password_is_used_and_remove_deletes_the_tester(): void
    {
        $this->actAsStaging();

        $this->artisan('staging:tester', ['email' => 'qa@example.com'])
            ->expectsQuestion('Password (at least 10 characters)', 'tester-pass-123')
            ->expectsQuestion('Repeat the password', 'tester-pass-123')
            ->assertSuccessful();
        $this->assertTrue(Hash::check('tester-pass-123', (string) User::query()->where('email', 'qa@example.com')->value('password')));

        $this->artisan('staging:tester', ['email' => 'qa@example.com', '--remove' => true])
            ->expectsOutputToContain('Tester removed')
            ->assertSuccessful();
        $this->assertDatabaseMissing('users', ['email' => 'qa@example.com']);
    }

    public function test_the_staging_seeder_makes_only_made_up_clients_without_phones(): void
    {
        $this->actAsStaging();

        $this->seed(StagingSeeder::class);
        $this->seed(StagingSeeder::class);

        $this->assertSame(5, Client::query()->count());
        $this->assertSame(0, Client::query()->whereNotNull('phone')->count());
        $this->assertSame(0, Client::query()->where('email', 'not like', '%@example.com')->count());
        $this->assertSame(6, ServiceRequest::query()->where('number', 'like', 'REQ-TEST-%')->count());
    }

    public function test_the_staging_seeder_refuses_outside_staging(): void
    {
        $this->expectException(RuntimeException::class);

        $this->seed(StagingSeeder::class);
    }

    public function test_staging_skips_jobs_that_touch_shared_inboxes_social_or_live_odoo(): void
    {
        $this->app['env'] = 'staging';
        $schedule = $this->app->make(Schedule::class);
        $due = collect($schedule->events())
            ->filter(fn (Event $event): bool => $event->filtersPass($this->app))
            ->map(fn (Event $event): string => (string) $event->command)
            ->implode("\n");

        foreach (['social:publish-due', 'odoo:reconcile', 'odoo:sync-invoices', 'ops:complaint-email-replies', 'ops:dev-digest'] as $skipped) {
            $this->assertStringNotContainsString($skipped, $due);
        }
        foreach (['ops:process-reminders', 'ops:poll-drive', 'clickup:retry-tasks'] as $kept) {
            $this->assertStringContainsString($kept, $due);
        }
    }

    private function actAsStaging(): void
    {
        $this->app['env'] = 'staging';
        config(['database.connections.'.config('database.default').'.database' => StagingGuard::DATABASE]);
    }

    private function assertGuardFails(string $environment, string $database): void
    {
        try {
            StagingGuard::assertDatabase($environment, $database);
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail("Guard allowed {$environment} on «{$database}».");
    }
}
