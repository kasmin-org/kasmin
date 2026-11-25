<?php

namespace App\Support\Mail;

use App\Models\Event;
use App\Models\Group;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\MailTemplate;
use App\Models\OutgoingMail;
use App\Models\Participation;
use App\Models\Person;
use App\Support\Settings;
use Illuminate\Support\Carbon;

/**
 * Turns a template into queued mails for a recipient (MS01, MT03, MT06): placeholders, layout with the footer links
 * (CO03), plain-text part.
 */
class Composer
{
    public function __construct(private readonly TemplateRenderer $renderer, private readonly Placeholders $placeholders) {}

    /**
     * @param  array{event?: Event|null, participation?: Participation|null, household?: Household|null, invoice?: Invoice|null, children?: list<Person>, login?: bool, group?: Group|null, offer_until?: Carbon|null}  $about
     * @param  list<array<string, mixed>>|null  $attachments  generated attachments, e.g. [['invoice_id' => 1, 'paid' => false]] (MT08)
     */
    public function queue(string|MailTemplate $template, Person $recipient, array $about = [], string $kind = 'transactional', string $status = 'queued', ?int $mailingId = null, ?array $attachments = null): ?OutgoingMail
    {
        $template = is_string($template) ? MailTemplate::query()->where('key', $template)->where('active', true)->with('contents')->first() : $template;
        $address = $recipient->primaryEmail();
        if ($template === null || $address === null) {
            return null;
        }
        $static = array_map(fn (string $path) => ['file' => $path, 'name' => basename($path)], array_values($template->attachments ?? []));
        $attachments = [...$static, ...($attachments ?? [])] ?: null;
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
            'text' => $rendered['text']."\n\n-- \n".self::textFooter().__('mail.footer_preferences', [], $locale).': '.$context['links']['preferences'],
            'kind' => $kind,
            'status' => $status,
            'attachments' => $attachments,
            'created_by_id' => auth()->id(),
        ]);
    }

    private static function textFooter(): string
    {
        $footer = trim((string) Settings::get('mail_footer'));

        return $footer === '' ? '' : $footer."\n\n";
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

    /** Colour of buttons and of the line on top (MT06). */
    public static function colour(): string
    {
        $colour = (string) Settings::get('mail_color');

        return preg_match('/^#[0-9a-f]{6}$/i', $colour) === 1 ? $colour : '#1da029';
    }

    public function layout(string $subject, string $body, string $locale, ?string $preferences): string
    {
        return view('mail.layout', [
            'subject' => $subject, 'body' => TemplateHtml::buttons($body, self::colour()), 'locale' => $locale,
            'organisation' => Placeholders::organisationName(), 'preferences' => $preferences,
        ])->render();
    }
}
