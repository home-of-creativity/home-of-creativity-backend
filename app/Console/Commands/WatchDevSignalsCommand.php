<?php

namespace App\Console\Commands;

use App\Enums\IntegrationEventStatus;
use App\Models\IntegrationEvent;
use App\Services\DevAlert;
use App\Services\DevBeat;
use App\Services\DevDigest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class WatchDevSignalsCommand extends Command
{
    protected $signature = 'ops:watch-signals';

    protected $description = 'Alert once when bots, the queue, or the integration outbox need a developer.';

    public function handle(DevAlert $alert, DevBeat $beats, DevDigest $digest): int
    {
        foreach ($digest->labels() as $name => $label) {
            $age = $beats->ageSeconds($name);
            $seen = 'dev.bots.seen.'.$name;
            if ($age !== null && $age <= 600) {
                Cache::put($seen, true, now()->addDays(7));
                $alert->recover('bot-'.$name, $label.': يعمل');

                continue;
            }

            if (! Cache::get($seen)) {
                continue;
            }

            $alert->once('bot-'.$name, $label.': متوقف', 1440);
        }

        $count = (int) DB::table('failed_jobs')->count();
        $previous = Cache::get('dev.queue.count');
        Cache::put('dev.queue.count', $count, now()->addDays(7));
        if ($previous !== null && $count > (int) $previous) {
            $alert->send('فشل جديد في الطابور: '.($count - (int) $previous).' (الإجمالي '.$count.')');
        }

        $stuck = IntegrationEvent::query()
            ->where('status', IntegrationEventStatus::Failed)
            ->where('attempts', '>=', 5)
            ->orderBy('id')
            ->limit(5)
            ->get(['event_uuid', 'event_type', 'request_number', 'attempts', 'last_error']);

        foreach ($stuck as $event) {
            $error = mb_substr((string) $event->last_error, 0, 160);
            $alert->once(
                'outbox-'.$event->event_uuid,
                'تكامل عالق '.$event->request_number.' '.$event->event_type->value.' محاولات '.$event->attempts.($error !== '' ? "\n".$error : ''),
                1440,
            );
        }

        return self::SUCCESS;
    }
}
