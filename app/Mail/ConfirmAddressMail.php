<?php

namespace App\Mail;

use App\Support\Mail\Placeholders;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConfirmAddressMail extends Mailable
{
    use Queueable, SerializesModels;

    /** $old is the address that is replaced, or null when the new one is added next to the others. */
    public function __construct(public string $name, public string $url, public ?string $old = null, public string $new = '') {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('portal.mail_address_subject', ['organisation' => Placeholders::organisationName()]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.simple', text: 'mail.simple-text', with: [
            'text' => __('portal.mail_address_text', ['name' => $this->name]).' '.__($this->old !== null ? 'portal.address_replace' : 'portal.address_add', ['old' => (string) $this->old, 'new' => $this->new]), 'button' => __('public.confirm'), 'url' => $this->url, 'note' => __('portal.mail_address_note'),
        ]);
    }
}
