<?php

namespace App\Actions;

use App\Contracts\WhatsAppMessenger;
use App\Mail\ClientDocumentMail;
use App\Models\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DeliverClientDocument
{
    public function __construct(private WhatsAppMessenger $whatsapp) {}

    public function send(Client $client, string $channel, string $text, ?string $absolutePath = null, ?string $filename = null): bool
    {
        try {
            if ($channel === 'email') {
                if (! filled($client->email)) {
                    return false;
                }

                $mailer = filled(config('mail.mailers.reports.password')) ? 'reports' : (string) config('mail.default');
                Mail::mailer($mailer)->to((string) $client->email)->send(new ClientDocumentMail($text, $absolutePath, $filename));

                return true;
            }

            if (! filled($client->phone) || ! $this->whatsapp->configured()) {
                return false;
            }

            if (is_string($absolutePath) && is_file($absolutePath)) {
                $this->whatsapp->sendDocument((string) $client->phone, $absolutePath, $text, $filename ?: 'document.pdf');
            } else {
                $this->whatsapp->sendText((string) $client->phone, $text);
            }

            return true;
        } catch (Throwable $exception) {
            Log::warning('Client document delivery failed.', [
                'client' => $client->id,
                'channel' => $channel,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
