<?php

namespace Database\Seeders;

use App\Enums\RequestSource;
use App\Enums\RequestStatus;
use App\Models\Client;
use App\Models\PricingPackage;
use App\Models\ServiceRequest;
use App\Support\StagingGuard;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Made-up clients and requests for the testers' copy. No phone and only @example.com emails,
 * so nothing the copy sends can reach a real person.
 */
class StagingSeeder extends Seeder
{
    /** @var list<array{name: string, company: string, email: string}> */
    private const CLIENTS = [
        ['name' => 'عميل تجريبي 1', 'company' => 'شركة الاختبار الأولى', 'email' => 'demo-client-1@example.com'],
        ['name' => 'عميل تجريبي 2', 'company' => 'متجر الاختبار', 'email' => 'demo-client-2@example.com'],
        ['name' => 'عميل تجريبي 3', 'company' => 'مطعم الاختبار', 'email' => 'demo-client-3@example.com'],
        ['name' => 'Demo Client 4', 'company' => 'Test Studio', 'email' => 'demo-client-4@example.com'],
        ['name' => 'Demo Client 5', 'company' => 'Sample Clinic', 'email' => 'demo-client-5@example.com'],
    ];

    /** @var list<array{client: int, status: RequestStatus, title: string}> */
    private const REQUESTS = [
        ['client' => 0, 'status' => RequestStatus::Submitted, 'title' => 'طلب تجريبي: هوية بصرية'],
        ['client' => 0, 'status' => RequestStatus::QuotationSent, 'title' => 'طلب تجريبي: إدارة حسابات'],
        ['client' => 1, 'status' => RequestStatus::AwaitingPayment, 'title' => 'طلب تجريبي: تصوير منتجات'],
        ['client' => 2, 'status' => RequestStatus::InProgress, 'title' => 'طلب تجريبي: محتوى شهري'],
        ['client' => 3, 'status' => RequestStatus::ReadyForReview, 'title' => 'Demo request: website'],
        ['client' => 4, 'status' => RequestStatus::Completed, 'title' => 'Demo request: logo'],
    ];

    public function run(): void
    {
        $database = config('database.connections.'.config('database.default').'.database');
        if (! StagingGuard::isStagingDatabase((string) app()->environment(), $database)) {
            throw new RuntimeException('StagingSeeder runs only with APP_ENV=staging on the '.StagingGuard::DATABASE.' database.');
        }

        $packageId = PricingPackage::query()->where('is_published', true)->orderBy('sort_order')->value('id');

        $clients = [];
        foreach (self::CLIENTS as $row) {
            $client = Client::withTrashed()->updateOrCreate(
                ['email' => $row['email']],
                [
                    'name' => $row['name'],
                    'company_name' => $row['company'],
                    'phone' => null,
                    'telegram_user_id' => null,
                    'locale' => 'ar',
                ],
            );
            if ($client->trashed()) {
                $client->restore();
            }
            $clients[] = $client;
        }

        foreach (self::REQUESTS as $index => $row) {
            $paid = in_array($row['status'], [RequestStatus::InProgress, RequestStatus::ReadyForReview, RequestStatus::Completed], true);
            ServiceRequest::query()->updateOrCreate(
                ['number' => sprintf('REQ-TEST-%04d', $index + 1)],
                [
                    'client_id' => $clients[$row['client']]->id,
                    'title' => $row['title'],
                    'description' => 'بيانات تجريبية لنسخة الاختبار.',
                    'status' => $row['status'],
                    'source' => RequestSource::Website,
                    'pricing_package_id' => $packageId,
                    'paid_at' => $paid ? now()->subDays(3) : null,
                ],
            );
        }
    }
}
