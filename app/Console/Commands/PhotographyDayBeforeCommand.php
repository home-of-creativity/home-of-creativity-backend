<?php

namespace App\Console\Commands;

use App\Actions\NotifyClientChannels;
use App\Actions\NotifyEmployees;
use App\Enums\EmployeeProfession;
use App\Models\PhotographyBooking;
use App\Models\ServiceRequest;
use App\Services\TelegramNotifier;
use App\Support\PhotographyChime;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class PhotographyDayBeforeCommand extends Command
{
    protected $signature = 'ops:photography-day-before';

    protected $description = 'Send a day-before photography reminder with a short chime.';

    public function handle(
        TelegramNotifier $telegram,
        NotifyClientChannels $clients,
        NotifyEmployees $employees,
    ): int {
        $now = Carbon::parse(now('Asia/Damascus')->format('Y-m-d H:i:s'), 'UTC');
        $bookings = PhotographyBooking::query()
            ->with('request.client')
            ->where('status', 'confirmed')
            ->whereNull('reminded_at')
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->copy()->addDay())
            ->get();

        foreach ($bookings as $booking) {
            $request = $booking->request;
            if (! $request instanceof ServiceRequest || $booking->starts_at === null) {
                continue;
            }
            $when = $booking->starts_at->format('Y-m-d H:i');
            $text = "تذكير: موعد التصوير غداً في {$when}.";
            $chatId = $request->client?->telegram_user_id;
            $sent = false;
            if (is_string($chatId) && $chatId !== '') {
                try {
                    $telegram->sendAudio($chatId, PhotographyChime::path(), $text);
                    $sent = true;
                } catch (Throwable) {
                    $sent = false;
                }
            }
            if (! $sent) {
                $clients->send($request, $text);
            }
            $employees->handle($request, EmployeeProfession::Media, $text);
            $booking->forceFill(['reminded_at' => now()])->save();
        }

        return self::SUCCESS;
    }
}
