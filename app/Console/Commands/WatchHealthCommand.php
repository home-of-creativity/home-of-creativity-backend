<?php

namespace App\Console\Commands;

use App\Actions\RepairWhatsAppBridge;
use App\Services\DevAlert;
use App\Services\DevBeat;
use App\Services\DevHealth;
use App\Services\WhatsAppWebClient;
use App\Support\ClientChannelGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class WatchHealthCommand extends Command
{
    protected $signature = 'ops:watch-health';

    protected $description = 'Alert developers once when the public API health check is down.';

    public function handle(DevAlert $alert, DevBeat $beats, DevHealth $health, WhatsAppWebClient $whatsApp): int
    {
        $beats->touch('scheduler');
        $this->watchWhatsApp($alert, $whatsApp);
        $url = $health->labelUrl();
        $up = $health->up();
        $wasDown = (bool) Cache::get('dev.health.down');

        if ($up) {
            Cache::forget('dev.health.misses');
            if ($wasDown) {
                Cache::forget('dev.health.down');
                $alert->send('Server up: '.$url);
                $this->info('Server up');
            }

            return self::SUCCESS;
        }

        $misses = ((int) Cache::get('dev.health.misses')) + 1;
        Cache::put('dev.health.misses', $misses, now()->addMinutes(10));
        if ($wasDown || $misses < 3) {
            return self::SUCCESS;
        }

        Cache::put('dev.health.down', true, now()->addDay());
        $alert->send('Server Down: '.$url);
        $this->warn('Server Down');

        return self::SUCCESS;
    }

    /**
     * Every minute: a linked bridge stays quiet. Two misses tell the developers once an hour.
     * A stuck socket exits on its own so Docker starts it again. A scan code still needs the phone.
     */
    private function watchWhatsApp(DevAlert $alert, WhatsAppWebClient $whatsApp): void
    {
        if (! ClientChannelGate::usesWhatsAppWeb()) {
            return;
        }

        $status = $whatsApp->status();
        $linked = $status['reachable'] && $status['connected'] && $status['qr'] === null;
        if ($linked) {
            Cache::forget('dev.wa.misses');
            $alert->recover('whatsapp-bridge', 'جسر واتساب متصل.');

            return;
        }

        $misses = ((int) Cache::get('dev.wa.misses')) + 1;
        Cache::put('dev.wa.misses', $misses, now()->addMinutes(10));
        if ($misses < 2) {
            return;
        }

        $text = match (true) {
            ! $status['reachable'] => 'جسر واتساب لا يرد. إذا تعطلت العملية يعيد دوكر تشغيلها. لا تُرسل هذه الرسالة للعميل.',
            $status['qr'] !== null => 'جسر واتساب يطلب مسحاً جديداً من صفحة القنوات. لا تُرسل هذه الرسالة للعميل.',
            default => 'جسر واتساب انقطع ويحاول الرجوع. لا تُرسل هذه الرسالة للعميل.',
        };
        $alert->once('whatsapp-bridge', $text, 60);
        if (Cache::add('dev.wa.repair', true, now()->addMinutes(10))) {
            app(RepairWhatsAppBridge::class)->handOff($status);
        }
    }
}
