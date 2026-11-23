<?php

namespace App\Support\Mail;

use App\Models\Event;
use App\Models\Household;
use App\Models\MailTemplate;
use App\Models\OutgoingMail;
use App\Models\Participation;
use App\Models\Person;

/**
 * Turns a template into queued mails for a recipient (MS01, MT03, MT06): placeholders, layout with the footer links
 * (CO03), plain-text part.
 */
class Composer
{
    public function __construct(private readonly TemplateRenderer $renderer, private readonly Placeholders $placeholders) {}

    /**
     * @param  array{event?: Event|null, participation?: Participation|null, household?: Household|null, children?: list<Person>, login?: bool}  $about
     */
    public function queue(string|MailTemplate $template, Person $recipient, array $about = [], string $kind = 'transactional', string $status = 'queued', ?int $mailingId = null): ?OutgoingMail
    {
        $template = is_string($template) ? MailTemplate::query()->where('key', $template)->where('active', true)->with('contents')->first() : $template;
        $address = $recipient->primaryEmail();
        if ($template === null || $address === null) {
            return null;
        }
        $locale = $recipient->locale;
        $context = $this->placeholders->for($recipient, $locale, $about);
        $rendered = $this->renderer->render($template, $locale, $context);

        return OutgoingMail::query()->create([
            'sender_profile_id' => $template->sender_profile_id,
            'mail_template_id' => $template->id,
            'template_version' => $template->version,
            'mailing_id' => $mailingId,
            'person_id' => $recipient->id,
            'household_id' => $about['household']->id ?? null,
            'to_address' => $address,
            'to_name' => $recipient->fullName(),
            'subject' => $rendered['subject'],
            'html' => $this->layout($rendered['subject'], $rendered['html'], $locale, $context['links']['preferences']),
            'text' => $rendered['text']."\n\n-- \n".__('mail.footer_preferences', [], $locale).': '.$context['links']['preferences'],
            'kind' => $kind,
            'status' => $status,
            'created_by_id' => auth()->id(),
        ]);
    }

    /**
     * A mail without template (e.g. admin notifications).
     */
    public function queueRaw(string $to, ?string $toName, string $subject, string $html, string $locale, string $kind = 'notification', ?int $personId = null): OutgoingMail
    {
        return OutgoingMail::query()->create([
            'to_address' => $to, 'to_name' => $toName, 'subject' => $subject, 'person_id' => $personId,
            'html' => $this->layout($subject, $html, $locale, null), 'text' => TemplateRenderer::toText($html),
            'kind' => $kind, 'status' => 'queued',
        ]);
    }

    public function layout(string $subject, string $body, string $locale, ?string $preferences): string
    {
        return view('mail.layout', [
            'subject' => $subject, 'body' => $body, 'locale' => $locale,
            'organisation' => Placeholders::organisationName(), 'preferences' => $preferences,
        ])->render();
    }
}
