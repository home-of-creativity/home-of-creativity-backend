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
Schedule::command('social:sync-accounts')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('integration:process-outbox')->everyMinute()->withoutOverlapping();
Schedule::command('odoo:reconcile')->everyMinute()->withoutOverlapping();
Schedule::command('ops:process-reminders')->everyMinute()->timezone('Asia/Damascus')->withoutOverlapping();
Schedule::command('ops:poll-drive')->everyFiveMinutes()->timezone('Asia/Damascus')->withoutOverlapping();
Schedule::command('ops:clickup-due-alerts')->hourly()->timezone('Asia/Damascus')->withoutOverlapping();
