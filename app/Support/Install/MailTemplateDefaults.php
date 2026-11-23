<?php

namespace App\Support\Install;

use App\Models\MailTemplate;

/**
 * Default mail templates (German and English) installed with the defaults (H01, MT01). Placeholders: see
 * docs/admin/mail.md. Salutations come from {{ salutation }} (gender-neutral, Sie/du by age, MT05).
 */
class MailTemplateDefaults
{
    public const VERSION = 1;

    public function install(): void
    {
        foreach ($this->templates() as $key => [$category, $audience, $name, $de, $en]) {
            if (MailTemplate::query()->where('key', $key)->exists()) {
                continue;
            }
            $template = MailTemplate::query()->create([
                'key' => $key, 'category' => $category, 'audience' => $audience, 'name' => $name,
                'is_standard' => true, 'standard_version' => self::VERSION,
            ]);
            $template->contents()->create(['locale' => 'de', 'subject' => $de[0], 'html' => $de[1]]);
            $template->contents()->create(['locale' => 'en', 'subject' => $en[0], 'html' => $en[1]]);
        }
    }

    /** @return array<string, array{0: string, 1: string, 2: array<string, string>, 3: array{0: string, 1: string}, 4: array{0: string, 1: string}}> */
    private function templates(): array
    {
        $button = fn (string $label) => '<p><a href="{{ links.login }}" style="display:inline-block;background:#1da029;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600;">'.$label.'</a></p>';

        return [
            'registration_confirmation' => ['registration', 'guardians', ['de' => 'Anmeldebestätigung', 'en' => 'Registration confirmation'], [
                'Anmeldung für {{ event.title }}: {{ registration.child }}',
                '<p>{{ salutation }}</p><p>vielen Dank! {{ registration.child }} ist für <strong>{{ event.title }}</strong> ({{ event.starts_at }} – {{ event.ends_at }}) angemeldet. Status: <strong>{{ registration.status }}</strong>.</p>{% if registration.waitlist_position %}<p>Ihr Kind steht auf der Warteliste auf <strong>Platz {{ registration.waitlist_position }} von {{ registration.waitlist_total }}</strong>. Wir melden uns, sobald ein Platz frei wird.</p>{% endif %}<p>Alle Angaben und den aktuellen Stand sehen Sie im Portal.</p>'.$button('Zum Portal').'<p>Mit freundlichen Grüßen<br>{{ organisation.name }}</p>',
            ], [
                'Registration for {{ event.title }}: {{ registration.child }}',
                '<p>{{ salutation }}</p><p>thank you! {{ registration.child }} is registered for <strong>{{ event.title }}</strong> ({{ event.starts_at }} – {{ event.ends_at }}). Status: <strong>{{ registration.status }}</strong>.</p>{% if registration.waitlist_position %}<p>Your child is on the waitlist at <strong>position {{ registration.waitlist_position }} of {{ registration.waitlist_total }}</strong>. We will contact you as soon as a place becomes free.</p>{% endif %}<p>You can see all details and the current status in the portal.</p>'.$button('Open the portal').'<p>Kind regards<br>{{ organisation.name }}</p>',
            ]],
            'safety_sheet_request' => ['safety_sheet', 'guardians', ['de' => 'Sicherheitsblatt ausfüllen', 'en' => 'Fill in the safety sheet'], [
                'Bitte Sicherheitsblatt ausfüllen oder bestätigen',
                '<p>{{ salutation }}</p><p>für {{ event.title }} brauchen wir das aktuelle Sicherheitsblatt{% if children|length > 0 %} von {% for child in children %}{{ child.first_name }}{% if not loop.last %}, {% endif %}{% endfor %}{% endif %}. Wenn sich nichts geändert hat, bestätigen Sie die Angaben im Portal mit einem Klick.</p>'.$button('Sicherheitsblatt öffnen').'<p>Die Angaben selbst stehen aus Datenschutzgründen nicht in dieser E-Mail.</p><p>Mit freundlichen Grüßen<br>{{ organisation.name }}</p>',
            ], [
                'Please fill in or confirm the safety sheet',
                '<p>{{ salutation }}</p><p>for {{ event.title }} we need the current safety sheet{% if children|length > 0 %} of {% for child in children %}{{ child.first_name }}{% if not loop.last %}, {% endif %}{% endfor %}{% endif %}. If nothing has changed, confirm the information in the portal with one click.</p>'.$button('Open the safety sheet').'<p>For data protection reasons the information itself is not included in this e-mail.</p><p>Kind regards<br>{{ organisation.name }}</p>',
            ]],
            'safety_sheet_reminder' => ['reminder', 'guardians', ['de' => 'Erinnerung Sicherheitsblatt', 'en' => 'Safety sheet reminder'], [
                'Erinnerung: Sicherheitsblatt für {{ event.title }}',
                '<p>{{ salutation }}</p><p>das Sicherheitsblatt{% if children|length > 0 %} von {% for child in children %}{{ child.first_name }}{% if not loop.last %}, {% endif %}{% endfor %}{% endif %} fehlt noch oder ist nicht bestätigt. Bitte erledigen Sie das im Portal – es dauert nur wenige Minuten.</p>'.$button('Jetzt erledigen').'<p>Mit freundlichen Grüßen<br>{{ organisation.name }}</p>',
            ], [
                'Reminder: safety sheet for {{ event.title }}',
                '<p>{{ salutation }}</p><p>the safety sheet{% if children|length > 0 %} of {% for child in children %}{{ child.first_name }}{% if not loop.last %}, {% endif %}{% endfor %}{% endif %} is still missing or not confirmed. Please complete it in the portal – it only takes a few minutes.</p>'.$button('Do it now').'<p>Kind regards<br>{{ organisation.name }}</p>',
            ]],
            'event_information' => ['event_information', 'guardians', ['de' => 'Informationen zur Veranstaltung', 'en' => 'Event information'], [
                'Informationen zu {{ event.title }}',
                '<p>{{ salutation }}</p><p>hier die wichtigsten Informationen zu <strong>{{ event.title }}</strong>:</p><ul><li>Beginn: {{ event.starts_at }}</li><li>Ende: {{ event.ends_at }}</li><li>Ort: {{ event.location }}</li><li>Treffpunkt: {{ event.meeting_point }}</li></ul><p>Mit freundlichen Grüßen<br>{{ organisation.name }}</p>',
            ], [
                'Information about {{ event.title }}',
                '<p>{{ salutation }}</p><p>here is the most important information about <strong>{{ event.title }}</strong>:</p><ul><li>Start: {{ event.starts_at }}</li><li>End: {{ event.ends_at }}</li><li>Place: {{ event.location }}</li><li>Meeting point: {{ event.meeting_point }}</li></ul><p>Kind regards<br>{{ organisation.name }}</p>',
            ]],
            'newsletter' => ['newsletter', 'both', ['de' => 'Newsletter', 'en' => 'Newsletter'], [
                'Neuigkeiten von {{ organisation.name }}',
                '<p>{{ salutation }}</p><p>(Text des Newsletters)</p><p>Mit freundlichen Grüßen<br>{{ organisation.name }}</p>',
            ], [
                'News from {{ organisation.name }}',
                '<p>{{ salutation }}</p><p>(Text of the newsletter)</p><p>Kind regards<br>{{ organisation.name }}</p>',
            ]],
        ];
    }
}
