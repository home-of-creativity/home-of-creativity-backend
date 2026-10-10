<?php

namespace App\Actions;

use App\Mail\ClientMessageEscalated;
use App\Models\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails a client message the bot could not handle to the developer, with the
 * conversation and the client's records, so the developer can answer the client
 * and fix the cause. The same message is sent once, and one client sends at most
 * six emails an hour.
 */
class EscalateClientMessage
{
    private const HOURLY_LIMIT = 6;

    /**
     * @param  list<array{role: string, text: string}>  $history
     */
    public function handle(Client $client, string $message, string $reason, array $history, string $channel, string $step): bool
    {
        $to = trim((string) config('services.client_assistant.escalation_email'));
        $message = trim($message);
        if ($to === '' || $message === '') {
            return false;
        }

        $fingerprint = 'assistant-escalation:'.$client->id.':'.sha1(mb_strtolower($message));
        if (! Cache::add($fingerprint, 1, now()->addHours(12))) {
            return false;
        }
        $hourly = 'assistant-escalation-hour:'.$client->id;
        Cache::add($hourly, 0, now()->addHour());
        if ((int) Cache::increment($hourly) > self::HOURLY_LIMIT) {
            Log::warning('Client escalation skipped: hourly limit.', ['client_id' => $client->id]);

            return false;
        }

        try {
            $records = app(ClientAssistant::class)->records($client);
        } catch (Throwable) {
            $records = '';
        }

        $mail = new ClientMessageEscalated(
            client: [
                'name' => (string) $client->name,
                'company' => (string) ($client->company_name ?? ''),
                'phone' => (string) ($client->phone ?? ''),
                'email' => (string) ($client->email ?? ''),
            ],
            channel: $channel === 'whatsapp' ? 'واتساب' : 'تيليجرام',
            messageText: mb_substr($message, 0, 4000),
            reason: $reason !== '' ? $reason : 'The assistant marked this message as not understood.',
            step: $step !== '' ? $step : 'idle',
            history: array_slice($history, -10),
            records: $records,
            chatUrl: $client->telegramPrivateUrl(),
            dashboardUrl: 'https://hoc.agency/dashboard/clients',
        );

        try {
            $mailer = filled(config('mail.mailers.reports.password')) ? 'reports' : (string) config('mail.default');
            Mail::mailer($mailer)->to($to)->send($mail);
        } catch (Throwable $exception) {
            Cache::forget($fingerprint);
            Log::error('Client escalation email failed.', [
                'client_id' => $client->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        Log::info('Client message escalated.', ['client_id' => $client->id, 'channel' => $channel]);

        return true;
    }
}
