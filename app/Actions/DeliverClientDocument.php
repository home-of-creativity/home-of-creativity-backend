<?php

namespace App\Actions;

use App\Mail\ClientDocumentMail;
use App\Models\Client;
use App\Services\WhatsAppWebClient;
use App\Support\ClientPhone;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DeliverClientDocument
{
    public function __construct(private WhatsAppWebClient $whatsapp) {}

    public function send(Client $client, string $channel, string $text, ?string $absolutePath = null, ?string $filename = null): bool
    {
        $channels = match ($channel) {
            'both' => ['email', 'whatsapp'],
            'phone', 'whatsapp' => ['whatsapp'],
            default => ['email'],
        };
        $delivered = true;
        foreach ($channels as $one) {
            $delivered = $this->sendOne($client, $one, $text, $absolutePath, $filename) && $delivered;
        }

        return $delivered;
    }

    private function sendOne(Client $client, string $channel, string $text, ?string $absolutePath, ?string $filename): bool
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

            // WhatsApp needs the international number; «0944…» alone does not arrive.
            $phone = ClientPhone::whatsappDigits((string) $client->phone);
            if ($phone === '' || ! $this->whatsapp->configured()) {
                return false;
            }

            if (is_string($absolutePath) && is_file($absolutePath)) {
                $this->whatsapp->sendDocument($phone, $absolutePath, $text, $filename ?: 'document.pdf');
            } else {
                $this->whatsapp->sendText($phone, $text);
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
