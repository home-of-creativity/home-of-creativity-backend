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
// --limit=500 keeps the whole client table hydrating from Odoo every run
// (not a rotating slice) so a stage/tag/contact edit made directly in Odoo
// reaches the dashboard within about a minute even as the client list grows.
Schedule::command('odoo:reconcile', ['--limit' => 500])->everyMinute()->withoutOverlapping();
Schedule::command('ops:process-reminders')->everyMinute()->timezone('Asia/Damascus')->withoutOverlapping();
Schedule::command('ops:poll-drive')->everyFiveMinutes()->timezone('Asia/Damascus')->withoutOverlapping();
Schedule::command('ops:clickup-due-alerts')->hourly()->timezone('Asia/Damascus')->withoutOverlapping();
Schedule::command('seo:submit-sitemap')->dailyAt('06:15')->timezone('Asia/Damascus')->withoutOverlapping();
