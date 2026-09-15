<?php

namespace App\Console\Commands;

use App\Actions\PurgeE2eDashboardData;
use Illuminate\Console\Command;

class PurgeE2eDataCommand extends Command
{
    protected $signature = 'e2e:purge {--keep-demo : Keep seeded demo requests for test@example.com}';

    protected $description = 'Delete Playwright E2E records from the dashboard database';

    public function handle(PurgeE2eDashboardData $purgeE2eDashboardData): int
    {
        $includeDemoRequests = ! $this->option('keep-demo');
        $result = $purgeE2eDashboardData->handle($includeDemoRequests);

        $this->info('E2E dashboard cleanup complete.');
        $this->line('Clients deleted: '.$result['clients']);
        $this->line('Requests deleted: '.$result['requests']);
        $this->line('Integration events deleted: '.$result['integration_events']);
        $this->line('Request files deleted: '.$result['request_files']);
        $this->line('Social accounts deleted: '.$result['social_accounts']);

        if ($includeDemoRequests) {
            $this->line('Demo requests removed from test@example.com: '.$result['demo_requests']);
        }

        return self::SUCCESS;
    }
}
