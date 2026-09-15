<?php

namespace Database\Seeders;

use App\Enums\EmployeeProfession;
use App\Enums\EmployeeStatus;
use App\Enums\RequestSource;
use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\Employee;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\SocialAccountSync;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DatabaseSeeder extends Seeder
{
    public function __construct(private SocialAccountSync $socialAccountSync) {}

    public function run(): void
    {
        $clientUser = User::query()->updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test Client',
                'password' => 'password',
                'phone' => '+963 968 862 822',
                'locale' => 'ar',
            ],
        );
        $clientUser->forceFill([
            'password' => 'password',
            'is_admin' => false,
        ])->save();

        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'HOC Admin',
                'password' => 'password',
                'locale' => 'ar',
            ],
        );
        $admin->forceFill([
            'password' => 'password',
            'is_admin' => true,
        ])->save();

        $client = Client::query()->firstOrCreate(
            ['user_id' => $clientUser->id],
            [
                'name' => $clientUser->name,
                'email' => $clientUser->email,
                'phone' => $clientUser->phone,
                'locale' => 'ar',
            ],
        );

        if ($client->requests()->doesntExist()) {
            ServiceRequest::factory()
                ->count(6)
                ->for($client)
                ->sequence(
                    ['status' => RequestStatus::Submitted, 'source' => RequestSource::Website],
                    ['status' => RequestStatus::QuotationSent],
                    ['status' => RequestStatus::PaymentConfirmed],
                    ['status' => RequestStatus::InProgress],
                    ['status' => RequestStatus::ReadyForReview],
                    ['status' => RequestStatus::Completed, 'source' => RequestSource::Telegram],
                )
                ->create();
        }

        Employee::query()->updateOrCreate(
            ['code' => 'EMP-0001'],
            [
                'name' => 'Sales Desk',
                'phone' => '+963 000 000 000',
                'telegram_user_id' => '6350001',
                'profession' => EmployeeProfession::Sales,
                'status' => EmployeeStatus::Approved,
                'notes' => 'Receives new client requests.',
                'is_active' => true,
            ],
        );

        Employee::query()->updateOrCreate(
            ['code' => 'EMP-0002'],
            [
                'name' => 'Design Desk',
                'phone' => '+963 000 000 001',
                'telegram_user_id' => '6350002',
                'clickup_user_id' => 'cu-design-e2e',
                'profession' => EmployeeProfession::Design,
                'status' => EmployeeStatus::Approved,
                'notes' => 'Executes assigned design tasks.',
                'is_active' => true,
            ],
        );

        $this->call(PortfolioSeeder::class);
        $this->call(PricingSeeder::class);
        $this->call(ContactSeeder::class);
        $this->call(LandingReelSeeder::class);
        $this->syncSocialAccounts($admin->id);
    }

    private function syncSocialAccounts(int $adminId): void
    {
        if (app()->environment('testing') || ! $this->socialAccountSync->configured()) {
            return;
        }

        $count = $this->socialAccountSync->syncFromFacebook($adminId);
        Cache::forget('social.landing.instagram.profile');
        Cache::forget('social.landing.facebook.profile');
        Cache::forget('social.landing.instagram.v2.200');
        Cache::forget('social.landing.facebook.v2.200');

        if ($count === 0 && filled($this->socialAccountSync->lastError)) {
            Log::warning('Social account seed sync skipped.', [
                'error' => $this->socialAccountSync->lastError,
            ]);
        }
    }
}
