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

        // Clients use SoftDeletes: look up (and restore) the same row across
        // deploys instead of creating a second client with a new id, which
        // would otherwise make the demo-request check below re-run against
        // an empty client and collide with the still-present old rows on
        // the globally unique `requests.number` column.
        $client = Client::withTrashed()->firstWhere('user_id', $clientUser->id);
        if ($client) {
            $client->restore();
            $client->forceFill([
                'name' => $clientUser->name,
                'email' => $clientUser->email,
                'phone' => $clientUser->phone,
                'locale' => 'ar',
            ])->save();
        } else {
            $client = Client::query()->create([
                'user_id' => $clientUser->id,
                'name' => $clientUser->name,
                'email' => $clientUser->email,
                'phone' => $clientUser->phone,
                'locale' => 'ar',
            ]);
        }

        $year = now()->year;
        $demoRequests = [
            ['number' => "REQ-{$year}-000001", 'title' => 'Brand identity refresh', 'description' => 'Logo, palette, and social templates for a retail launch.', 'status' => RequestStatus::Submitted, 'source' => RequestSource::Website],
            ['number' => "REQ-{$year}-000002", 'title' => 'Event booth design', 'description' => 'Exhibition stand visuals and print-ready artwork.', 'status' => RequestStatus::QuotationSent, 'source' => RequestSource::Website],
            ['number' => "REQ-{$year}-000003", 'title' => 'Product launch video', 'description' => 'Short promo edit with motion graphics and captions.', 'status' => RequestStatus::PaymentConfirmed, 'source' => RequestSource::Website],
            ['number' => "REQ-{$year}-000004", 'title' => 'Website landing page', 'description' => 'Bilingual landing page design and responsive layout.', 'status' => RequestStatus::InProgress, 'source' => RequestSource::Website],
            ['number' => "REQ-{$year}-000005", 'title' => 'Outdoor campaign artwork', 'description' => 'Billboard and storefront signage adaptations.', 'status' => RequestStatus::ReadyForReview, 'source' => RequestSource::Website],
            ['number' => "REQ-{$year}-000006", 'title' => 'Social media kit', 'description' => 'Monthly content templates delivered via Telegram.', 'status' => RequestStatus::Completed, 'source' => RequestSource::Telegram],
        ];

        // firstOrCreate per number (not a blanket "client has any request"
        // check) so a partially-seeded environment, or a request number
        // reused by another client row, never throws a duplicate-key error
        // and always leaves seeding idempotent.
        foreach ($demoRequests as $row) {
            ServiceRequest::query()->firstOrCreate(
                ['number' => $row['number']],
                [
                    'client_id' => $client->id,
                    'title' => $row['title'],
                    'description' => $row['description'],
                    'status' => $row['status'],
                    'source' => $row['source'],
                ],
            );
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
