<?php

namespace App\Console\Commands;

use App\Models\PaymentReminder;
use App\Models\ServiceRequest;
use App\Services\GoogleCalendarClient;
use App\Services\TelegramNotifier;
use App\Support\Money;
use App\Support\ResolveServiceRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessPaymentRemindersCommand extends Command
{
    protected $signature = 'ops:process-reminders {--limit=50}';

    protected $description = 'Send spaced Telegram reminders for remaining balance and renewal.';

    public function handle(TelegramNotifier $telegram, GoogleCalendarClient $calendar): int
    {
        $reminders = PaymentReminder::query()
            ->with(['request.client'])
            ->whereNull('completed_at')
            ->where('due_at', '<=', now())
            ->where('send_count', '<', PaymentReminder::MAX_SENDS)
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($reminders as $reminder) {
            if (! $reminder->isDue()) {
                continue;
            }

            $request = $reminder->request;
            if (! $request instanceof ServiceRequest) {
                continue;
            }

            if ($reminder->kind === PaymentReminder::KIND_REMAINING && ! $request->hasRemainingBalance()) {
                $reminder->forceFill(['completed_at' => now()])->save();

                continue;
            }

            if ($reminder->kind === PaymentReminder::KIND_RENEWAL) {
                if (! $request->allows_renewal) {
                    $reminder->forceFill(['completed_at' => now()])->save();

                    continue;
                }
                if ($request->subscription_ends_at && $request->subscription_ends_at->isPast()) {
                    $reminder->forceFill(['completed_at' => now()])->save();

                    continue;
                }
            }

            $this->ensureCalendarEvent($calendar, $reminder, $request);

            $chatId = $request->client?->telegram_user_id;
            if (! filled($chatId) || ! $telegram->configured('client')) {
                continue;
            }

            $ref = ResolveServiceRequest::displayNumber($request);

            try {
                if ($reminder->kind === PaymentReminder::KIND_REMAINING) {
                    $telegram->send(
                        (string) $chatId,
                        "تذكير بسداد المتبقي للطلب #{$ref}.\nالمتبقي: ".Money::format($request->amount_remaining),
                    );
                } else {
                    $telegram->sendInlineKeyboard(
                        (string) $chatId,
                        "اقترب موعد تجديد الاشتراك للطلب #{$ref}.",
                        [
                            [
                                ['text' => 'تجديد الاشتراك', 'callback_data' => "renew:{$ref}"],
                                ['text' => 'لن أجدد', 'callback_data' => "norenew:{$ref}"],
                            ],
                        ],
                    );
                }

                $sendCount = $reminder->send_count + 1;
                $reminder->forceFill([
                    'last_sent_at' => now(),
                    'send_count' => $sendCount,
                    'completed_at' => $sendCount >= PaymentReminder::MAX_SENDS ? now() : null,
                ])->save();
            } catch (Throwable $exception) {
                Log::warning('Payment reminder send failed.', [
                    'reminder_id' => $reminder->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }

    private function ensureCalendarEvent(
        GoogleCalendarClient $calendar,
        PaymentReminder $reminder,
        ServiceRequest $request,
    ): void {
        if (filled($reminder->google_event_id) || ! $calendar->configured()) {
            return;
        }

        $summary = $reminder->kind === PaymentReminder::KIND_REMAINING
            ? 'تذكير المتبقي — '.$request->number
            : 'تجديد الاشتراك — '.$request->number;

        try {
            $eventId = $calendar->createEvent(
                $summary,
                'طلب #'.$request->number.' — '.$request->title,
                $reminder->due_at ?? now(),
            );
            if (filled($eventId)) {
                $reminder->forceFill(['google_event_id' => $eventId])->save();
            }
        } catch (Throwable $exception) {
            Log::warning('Payment reminder calendar backfill failed.', [
                'reminder_id' => $reminder->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
