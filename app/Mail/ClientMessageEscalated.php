<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClientMessageEscalated extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, string>  $client
     * @param  list<array{role: string, text: string}>  $history
     */
    public function __construct(
        public array $client,
        public string $channel,
        public string $messageText,
        public string $reason,
        public string $step,
        public array $history,
        public string $records,
        public ?string $chatUrl,
        public ?string $dashboardUrl,
    ) {}

    public function envelope(): Envelope
    {
        $from = (string) config('mail.mailers.reports.username');

        return new Envelope(
            from: new Address($from !== '' ? $from : 'reports@hoc.agency', 'HOC Client Bot'),
            subject: 'رسالة عميل تحتاج متابعة: '.($this->client['name'] ?? '').' — '.mb_substr($this->messageText, 0, 60),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.client-message-escalated');
    }
}
