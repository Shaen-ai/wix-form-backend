<?php

namespace App\Mail;

use App\Models\Form;
use App\Models\Submission;
use App\Models\TenantSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AutoReplyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Submission $submission,
        public Form $form,
        public TenantSettings $settings
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->settings->auto_reply_subject ?? "Thank you for your submission",
            from: config('mail.from.address'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.auto-reply'
        );
    }
}
