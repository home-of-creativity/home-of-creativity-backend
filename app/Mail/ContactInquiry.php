<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactInquiry extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $senderName,
        public string $senderEmail,
        public string $phone,
        public string $interestLabel,
        public string $inquiry,
        public string $toAddress,
        public string $ccAddress,
        public string $subjectLine,
    ) {}

    public function envelope(): Envelope
    {
        $from = (string) config('mail.mailers.contact.username');

        return new Envelope(
            from: new Address($from !== '' ? $from : 'info@hoc.agency', 'Home of Creativity'),
            to: [new Address($this->toAddress)],
            cc: [new Address($this->ccAddress)],
            replyTo: [new Address($this->senderEmail, $this->senderName)],
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.contact-inquiry',
        );
    }
}
