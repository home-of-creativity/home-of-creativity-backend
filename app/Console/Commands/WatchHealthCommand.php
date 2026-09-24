<?php

namespace App\Console\Commands;

use App\Services\DevAlert;
use App\Services\DevBeat;
use App\Services\DevHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class WatchHealthCommand extends Command
{
    protected $signature = 'ops:watch-health';

    protected $description = 'Alert developers once when the public API health check is down.';

    public function handle(DevAlert $alert, DevBeat $beats, DevHealth $health): int
    {
        $beats->touch('scheduler');
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
        if ($wasDown || $misses < 2) {
            return self::SUCCESS;
        }

        Cache::put('dev.health.down', true, now()->addDay());
        $alert->send('Server Down: '.$url);
        $this->warn('Server Down');

        return self::SUCCESS;
    }
}
