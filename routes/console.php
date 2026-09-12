<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('social:publish-due')->everyMinute()->timezone('Asia/Damascus')->withoutOverlapping();
Schedule::command('social:sync-inbox')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('social:sync-posts')->everyFifteenMinutes()->withoutOverlapping();
