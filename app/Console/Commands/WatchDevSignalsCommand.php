<?php

namespace App\Console\Commands;

use App\Enums\IntegrationEventStatus;
use App\Models\IntegrationEvent;
use App\Services\DevAlert;
use App\Services\DevBeat;
use App\Services\DevDigest;
use App\Services\WhatsAppWebClient;
use App\Support\ClientChannelGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class WatchDevSignalsCommand extends Command
{
    protected $signature = 'ops:watch-signals';

    protected $description = 'Alert once when bots, the queue, or the integration outbox need a developer.';

    public function handle(DevAlert $alert, DevBeat $beats, DevDigest $digest, WhatsAppWebClient $whatsApp): int
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
            if ($event->stopsRetry()) {
                $event->forceFill([
                    'status' => IntegrationEventStatus::Abandoned,
                    'next_retry_at' => null,
                ])->save();

                continue;
            }

            $error = mb_substr((string) $event->last_error, 0, 160);
            $alert->once(
                'outbox-'.$event->event_uuid,
                'تكامل عالق '.$event->request_number.' '.$event->event_type->value.' محاولات '.$event->attempts.($error !== '' ? "\n".$error : ''),
                1440,
            );
        }

        if (ClientChannelGate::usesWhatsAppWeb()) {
            $status = $whatsApp->status();
            if (! $status['reachable'] || ! $status['connected'] || $status['qr'] !== null) {
                $alert->once('whatsapp-bridge', 'جسر واتساب انقطع أو يطلب مسحاً جديداً. لا تُرسل هذه الرسالة للعميل.', 60);
            } else {
                $alert->recover('whatsapp-bridge', 'جسر واتساب متصل.');
            }
        }

        return self::SUCCESS;
    }
}
