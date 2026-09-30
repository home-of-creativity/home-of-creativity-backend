<?php

namespace App\Console\Commands;

use App\Actions\EnqueueIntegrationEvent;
use App\Enums\IntegrationEventStatus;
use App\Models\IntegrationEvent;
use Illuminate\Console\Command;

class ProcessIntegrationOutbox extends Command
{
    protected $signature = 'integration:process-outbox {--limit=25}';

    protected $description = 'Retry pending or failed integration outbox events';

    public function handle(EnqueueIntegrationEvent $enqueue): int
    {
        $events = IntegrationEvent::query()
            ->where(function ($query): void {
                $query->where(function ($pending): void {
                    $pending->where('status', IntegrationEventStatus::Pending)
                        ->whereNull('next_retry_at');
                })->orWhere(function ($retry): void {
                    $retry->where('status', IntegrationEventStatus::Failed)
                        ->whereNotNull('next_retry_at')
                        ->where('next_retry_at', '<=', now());
                })->orWhere(function ($stopped): void {
                    $stopped->where('status', IntegrationEventStatus::Failed)
                        ->whereNull('next_retry_at')
                        ->where('attempts', '>=', 5);
                });
            })
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($events as $event) {
            if ($event->status === IntegrationEventStatus::Failed && $event->stopsRetry()) {
                $event->forceFill([
                    'status' => IntegrationEventStatus::Abandoned,
                    'next_retry_at' => null,
                ])->save();
                $this->line("Stopped {$event->event_uuid} ({$event->event_type->value})");

                continue;
            }

            $enqueue->retry($event);
            $this->line("Queued {$event->event_uuid} ({$event->event_type->value})");
        }

        return self::SUCCESS;
    }
}
