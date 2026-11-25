<?php

namespace App\Mail;

use App\Support\Mail\Placeholders;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AddressChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $name) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('portal.mail_address_changed_subject', ['organisation' => Placeholders::organisationName()]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.simple', text: 'mail.simple-text', with: [
            'text' => __('portal.mail_address_changed_text', ['name' => $this->name]), 'button' => __('public.confirm'), 'url' => null, 'note' => __('portal.mail_address_changed_note'),
        ]);
    }
}
