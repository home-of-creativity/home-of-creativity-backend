<?php

namespace App\Console\Commands;

use App\Services\DevAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class WatchHealthCommand extends Command
{
    protected $signature = 'ops:watch-health';

    protected $description = 'Alert developers once when the public API health check is down.';

    public function handle(DevAlert $alert): int
    {
        $url = (string) config('services.dev.health_url');
        $up = false;
        try {
            $up = Http::timeout(8)->get($url)->successful();
        } catch (\Throwable) {
            $up = false;
        }

        $wasDown = (bool) Cache::get('dev.health.down');
        if (! $up && ! $wasDown) {
            Cache::put('dev.health.down', true, now()->addDay());
            $alert->send('Server Down: '.$url);
            $this->warn('Server Down');

            return self::SUCCESS;
        }

        if ($up && $wasDown) {
            Cache::forget('dev.health.down');
            $alert->send('Server up: '.$url);
            $this->info('Server up');
        }

        return self::SUCCESS;
    }
}
