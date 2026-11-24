<?php

namespace App\Mail;

use App\Support\Mail\Placeholders;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConfirmSubmissionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $name, public string $form, public string $url, public int $days) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('public.mail_confirm_subject', ['organisation' => Placeholders::organisationName()]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.public-confirm', text: 'mail.public-confirm-text');
    }
}
