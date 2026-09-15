<?php

namespace App\Actions;

use App\Models\Client;
use App\Models\IntegrationEvent;
use App\Models\RequestFile;
use App\Models\ServiceRequest;
use App\Services\SocialAccountSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PurgeE2eDashboardData
{
    public function __construct(
        private SocialAccountSync $socialAccountSync,
    ) {}

    /**
     * @return array{
     *     clients: int,
     *     requests: int,
     *     integration_events: int,
     *     request_files: int,
     *     social_accounts: int,
     *     demo_requests: int
     * }
     */
    public function handle(bool $includeDemoRequests = true): array
    {
        return DB::transaction(function () use ($includeDemoRequests): array {
            $e2eClientIds = $this->e2eClientIds();
            $demoRequestIds = $includeDemoRequests ? $this->demoRequestIds() : [];
            $requestIds = $this->requestIdsToDelete($e2eClientIds, $demoRequestIds);
            $requestUuids = ServiceRequest::query()
                ->whereIn('id', $requestIds)
                ->pluck('uuid')
                ->filter()
                ->values()
                ->all();

            $requestFiles = RequestFile::query()->whereIn('request_id', $requestIds)->get();
            foreach ($requestFiles as $file) {
                if (filled($file->path)) {
                    Storage::delete($file->path);
                }
            }

            $integrationEvents = IntegrationEvent::query()
                ->whereIn('request_uuid', $requestUuids)
                ->delete();

            $deletedRequests = ServiceRequest::query()->whereIn('id', $requestIds)->delete();
            $deletedClients = Client::query()->whereIn('id', $e2eClientIds)->delete();
            $socialAccounts = $this->socialAccountSync->purgeTestAccounts();

            return [
                'clients' => $deletedClients,
                'requests' => $deletedRequests,
                'integration_events' => $integrationEvents,
                'request_files' => $requestFiles->count(),
                'social_accounts' => $socialAccounts,
                'demo_requests' => count($demoRequestIds),
            ];
        });
    }

    /**
     * @return list<int>
     */
    private function e2eClientIds(): array
    {
        return Client::query()
            ->where(function ($query): void {
                $query->where('name', 'like', 'E2E%')
                    ->orWhere('name', 'like', 'Live Telegram E2E%')
                    ->orWhere('telegram_user_id', 'like', 'tg-%');
            })
            ->pluck('id')
            ->all();
    }

    /**
     * @return list<int>
     */
    private function demoRequestIds(): array
    {
        return ServiceRequest::query()
            ->whereHas('client.user', fn ($query) => $query->where('email', 'test@example.com'))
            ->pluck('id')
            ->all();
    }

    /**
     * @param  list<int>  $e2eClientIds
     * @param  list<int>  $demoRequestIds
     * @return list<int>
     */
    private function requestIdsToDelete(array $e2eClientIds, array $demoRequestIds): array
    {
        $ids = ServiceRequest::query()
            ->where(function ($query) use ($e2eClientIds): void {
                if ($e2eClientIds !== []) {
                    $query->whereIn('client_id', $e2eClientIds);
                }

                $query->orWhere('title', 'like', 'E2E%')
                    ->orWhere('title', 'like', 'Odoo dashboard%')
                    ->orWhere('title', 'like', 'Odoo integration%')
                    ->orWhere('title', 'like', 'ClickUp tasks%')
                    ->orWhere('title', 'like', 'Telegram notify%')
                    ->orWhere('title', 'like', 'Landing E2E%')
                    ->orWhere('title', 'like', 'Live Telegram E2E%');
            })
            ->pluck('id')
            ->all();

        return array_values(array_unique([...$ids, ...$demoRequestIds]));
    }
}
