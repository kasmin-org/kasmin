<?php

namespace App\Mail;

use App\Support\Mail\Placeholders;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConfirmSignUpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $name, public string $url, public int $days) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('portal.mail_sign_up_subject', ['organisation' => Placeholders::organisationName()]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.simple', text: 'mail.simple-text', with: [
            'text' => __('portal.mail_sign_up_text', ['name' => $this->name]), 'button' => __('public.confirm'), 'url' => $this->url, 'note' => __('portal.mail_sign_up_note', ['days' => $this->days]),
        ]);
    }
}
