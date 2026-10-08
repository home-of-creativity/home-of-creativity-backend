<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ClientDocumentMail extends Mailable
{
    public function __construct(
        public string $body,
        public ?string $absolutePath = null,
        public ?string $filename = null,
    ) {}

    public function envelope(): Envelope
    {
        $from = (string) config('mail.mailers.reports.username');

        return new Envelope(
            from: new Address($from !== '' ? $from : 'reports@hoc.agency', 'Home of Creativity'),
            subject: 'Home of Creativity',
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: nl2br(e($this->body)));
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        if (! is_string($this->absolutePath) || ! is_file($this->absolutePath)) {
            return [];
        }

        return [
            Attachment::fromPath($this->absolutePath)
                ->as($this->filename ?: 'document.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
