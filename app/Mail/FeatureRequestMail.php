<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FeatureRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $fromEmail,
        public string $subject,
        public string $description
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Smart Form Feature Request] ' . $this->subject,
            replyTo: [$this->fromEmail],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.feature-request'
        );
    }
}
