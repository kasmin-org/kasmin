<?php

namespace App\Mail;

use App\Support\Mail\Placeholders;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GuardianInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $host, public string $url, public int $days) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('public.mail_guest_subject', ['organisation' => Placeholders::organisationName()]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.public-guardian', text: 'mail.public-guardian-text');
    }
}
