<?php

use App\Models\BotDraft;
use App\Support\StagingGuard;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The testers' copy has no shared inbox, social pages, live Odoo pull, sitemap, or developer alerts.
$notOnStaging = fn (): bool => app()->environment(StagingGuard::ENVIRONMENT);

Schedule::command('social:publish-due')->everyMinute()->timezone('Asia/Damascus')->withoutOverlapping()->skip($notOnStaging);
Schedule::command('social:sync-inbox')->everyFifteenMinutes()->withoutOverlapping()->skip($notOnStaging);
Schedule::command('social:sync-posts')->everyFifteenMinutes()->withoutOverlapping()->skip($notOnStaging);
Schedule::command('social:sync-accounts')->everyFifteenMinutes()->withoutOverlapping()->skip($notOnStaging);
Schedule::command('social:warm-feeds')->everyThreeHours()->timezone('Asia/Damascus')->withoutOverlapping()->skip($notOnStaging);
Schedule::command('integration:process-outbox')->everyMinute()->withoutOverlapping();
// --limit=500 keeps the whole client table hydrating from Odoo every run
// (not a rotating slice) so a stage/tag/contact edit made directly in Odoo
// reaches the dashboard within about a minute even as the client list grows.
// The overlap lock lives in the file cache: a run cut off by a deploy would otherwise
// hold it for the default 24 hours and stop these jobs.
Schedule::command('odoo:reconcile', ['--limit' => 500])->everyMinute()->withoutOverlapping(10)->skip($notOnStaging);
// Finance follows Odoo every minute: changed invoices, and the ones deleted in Odoo.
Schedule::command('odoo:sync-invoices')->everyMinute()->withoutOverlapping(10)->skip($notOnStaging);
Schedule::command('odoo:sync-quotations')->everyMinute()->withoutOverlapping(10)->skip($notOnStaging);
Schedule::command('odoo:sync-invoices', ['--full'])->hourly()->withoutOverlapping(30)->skip($notOnStaging);
Schedule::command('ops:process-reminders')->everyMinute()->timezone('Asia/Damascus')->withoutOverlapping();
Schedule::command('ops:process-bot-sla')->everyMinute()->timezone('Asia/Damascus')->withoutOverlapping();
Schedule::command('ops:poll-drive', ['--limit' => 200])->everyMinute()->timezone('Asia/Damascus')->withoutOverlapping();
Schedule::command('ops:renew-drive-watch')->hourly()->timezone('Asia/Damascus')->withoutOverlapping()->skip($notOnStaging);
Schedule::command('ops:clickup-due-alerts')->hourly()->timezone('Asia/Damascus')->withoutOverlapping();
Schedule::command('clickup:retry-tasks')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('ops:photography-day-before')->hourly()->timezone('Asia/Damascus')->withoutOverlapping();
Schedule::command('ops:photography-hours-before')->everyFiveMinutes()->timezone('Asia/Damascus')->withoutOverlapping();
Schedule::command('ops:satisfaction-survey')->everyFifteenMinutes()->timezone('Asia/Damascus')->withoutOverlapping()->skip($notOnStaging);
Schedule::command('ops:finance-weekly')->weeklyOn(0, '09:00')->timezone('Asia/Damascus')->withoutOverlapping()->skip($notOnStaging);
// Unanswered photography requests, offers, and moves close after 48 hours, even with Telegram down.
Schedule::command('ops:photography-expire')->everyTenMinutes()->timezone('Asia/Damascus')->withoutOverlapping();
// Client email answers to complaint tickets land on the ticket within two minutes.
Schedule::command('ops:complaint-email-replies')->everyTwoMinutes()->withoutOverlapping()->skip($notOnStaging);
Schedule::command('seo:submit-sitemap')->dailyAt('06:15')->timezone('Asia/Damascus')->withoutOverlapping()->skip($notOnStaging);
Schedule::command('ops:watch-health')->everyMinute()->withoutOverlapping()->skip($notOnStaging);
Schedule::command('ops:watch-signals')->everyFifteenMinutes()->withoutOverlapping()->skip($notOnStaging);
Schedule::command('ops:dev-digest')->dailyAt('09:00')->timezone('Asia/Damascus')->withoutOverlapping()->skip($notOnStaging);
Schedule::call(fn () => BotDraft::query()->where('updated_at', '<', now()->subDays(BotDraft::KEEP_DAYS))->delete())
    ->name('bot-drafts:prune')
    ->dailyAt('04:30')
    ->timezone('Asia/Damascus')
    ->withoutOverlapping();
