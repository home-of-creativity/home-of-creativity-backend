<?php

namespace App\Console\Commands;

use App\Actions\ProvisionClickUpTasks;
use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Services\ClickUpClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class RetryClickUpTasksCommand extends Command
{
    public const MAX_ATTEMPTS = 30;

    protected $signature = 'clickup:retry-tasks {--limit=20}';

    protected $description = 'Create the ClickUp tasks that failed after payment, once ClickUp answers again.';

    public function handle(ClickUpClient $clickUp, ProvisionClickUpTasks $provision): int
    {
        if (! $clickUp->configured()) {
            return self::SUCCESS;
        }

        $requests = ServiceRequest::query()
            ->whereNotNull('clickup_error')
            ->where('clickup_attempts', '<', self::MAX_ATTEMPTS)
            ->whereIn('status', [
                RequestStatus::PaymentConfirmed,
                RequestStatus::InProgress,
                RequestStatus::RevisionRequested,
                RequestStatus::ReadyForReview,
            ])
            ->orderBy('clickup_failed_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        foreach ($requests as $request) {
            try {
                $fresh = $provision->handle($request, (string) Str::uuid(), notifyOnFailure: false);
                $this->line($fresh->clickup_error === null ? "created {$fresh->number}" : "still failing {$fresh->number}");
            } catch (Throwable $exception) {
                Log::warning('clickup:retry-tasks failed.', [
                    'request' => $request->number,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }
}
