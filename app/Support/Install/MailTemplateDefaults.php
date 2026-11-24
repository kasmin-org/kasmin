<?php

namespace App\Support\Install;

use App\Models\MailTemplate;

/**
 * Default mail templates (German and English) installed with the defaults (H01, MT01). Placeholders: see
 * docs/admin/mail.md. Salutations come from {{ salutation }} (gender-neutral, Sie/du by age, MT05).
 */
class MailTemplateDefaults
{
    public const VERSION = 5;

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
            'application_welcome' => ['registration', 'both', ['de' => 'Willkommen (Bewerbung angenommen)', 'en' => 'Welcome (application accepted)'], [
                'Willkommen bei {{ organisation.name }}',
                '<p>{{ salutation }}</p><p>Ihre Bewerbung wurde angenommen – herzlich willkommen! Im Portal finden Sie Ihre Daten und Anmeldungen.</p>'.$button('Zum Portal').'<p>Mit freundlichen Grüßen<br>{{ organisation.name }}</p>',
            ], [
                'Welcome to {{ organisation.name }}',
                '<p>{{ salutation }}</p><p>your application was accepted – welcome! You find your data and registrations in the portal.</p>'.$button('Open the portal').'<p>Kind regards<br>{{ organisation.name }}</p>',
            ]],
            'guardianship_ended' => ['system', 'both', ['de' => 'Ende der Erziehungsberechtigung', 'en' => 'End of guardianship'], [
                'Änderung bei {{ children[0].full_name }}',
                '<p>{{ salutation }}</p><p>{{ children[0].full_name }} hat das Alter erreicht, ab dem die eigenen Daten selbst verwaltet werden. Erziehungsberechtigte haben im Portal keinen Zugriff mehr darauf; künftige Rechnungen gehen an {{ children[0].full_name }}.</p><p>Mit freundlichen Grüßen<br>{{ organisation.name }}</p>',
            ], [
                'Change for {{ children[0].full_name }}',
                '<p>{{ salutation }}</p><p>{{ children[0].full_name }} has reached the age from which people manage their own data. Legal guardians no longer have access in the portal; future invoices go to {{ children[0].full_name }}.</p><p>Kind regards<br>{{ organisation.name }}</p>',
            ]],
            'round_offer' => ['registration', 'guardians', ['de' => 'Platzangebot (Anmelderunde)', 'en' => 'Place offered (registration round)'], [
                '{{ event.title }}: Platz für {% for child in children %}{{ child.first_name }}{% if not loop.last %}, {% endif %}{% endfor %}',
                '<p>{{ salutation }}</p><p>für {% for child in children %}<strong>{{ child.first_name }}</strong>{% if not loop.last %}, {% endif %}{% endfor %} ist bei <strong>{{ event.title }}</strong> ({{ event.starts_at }} – {{ event.ends_at }}) ein Platz reserviert, bis <strong>{{ registration.offer_until }}</strong>.</p>{% if registration.by_payment %}<p>Mit der Bezahlung der beiliegenden Rechnung bis zu diesem Termin nehmen Sie den Platz an. Ohne Zahlung wird er weitergegeben.</p>{% else %}<p>Bitte nehmen Sie den Platz im Portal an oder sagen Sie ab. Ohne Antwort wird er weitergegeben.</p>{% endif %}'.$button('Zum Portal').'<p>Mit freundlichen Grüßen<br>{{ organisation.name }}</p>',
            ], [
                '{{ event.title }}: place for {% for child in children %}{{ child.first_name }}{% if not loop.last %}, {% endif %}{% endfor %}',
                '<p>{{ salutation }}</p><p>a place at <strong>{{ event.title }}</strong> ({{ event.starts_at }} – {{ event.ends_at }}) is reserved for {% for child in children %}<strong>{{ child.first_name }}</strong>{% if not loop.last %}, {% endif %}{% endfor %} until <strong>{{ registration.offer_until }}</strong>.</p>{% if registration.by_payment %}<p>By paying the attached invoice until then you accept the place. Without payment it is passed on.</p>{% else %}<p>Please accept or decline the place in the portal. Without an answer it is passed on.</p>{% endif %}'.$button('Open the portal').'<p>Kind regards<br>{{ organisation.name }}</p>',
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
            'invoice' => ['invoice', 'guardians', ['de' => 'Rechnung', 'en' => 'Invoice'], [
                'Rechnung {{ invoice.number }}{% if event %} – {{ event.title }}{% endif %}',
                '<p>{{ salutation }}</p><p>anbei erhalten Sie die Rechnung <strong>{{ invoice.number }}</strong>{% if event %} für {{ event.title }}{% endif %}{% if invoice.children %} ({{ invoice.children }}){% endif %} über <strong>{{ invoice.total }}</strong>.</p><p>Bitte überweisen Sie den Betrag bis <strong>{{ invoice.due_on }}</strong>{% if invoice.iban %} auf das Konto {{ invoice.iban }}{% endif %} mit dem Verwendungszweck <strong>{{ invoice.reference }}</strong>. Am einfachsten scannen Sie den QR-Code auf der Rechnung mit Ihrer Banking-App.</p><p>Die Rechnung finden Sie auch im Portal.</p>'.$button('Rechnung im Portal ansehen').'<p>Mit freundlichen Grüßen<br>{{ organisation.name }}</p>',
            ], [
                'Invoice {{ invoice.number }}{% if event %} – {{ event.title }}{% endif %}',
                '<p>{{ salutation }}</p><p>please find attached invoice <strong>{{ invoice.number }}</strong>{% if event %} for {{ event.title }}{% endif %}{% if invoice.children %} ({{ invoice.children }}){% endif %} for <strong>{{ invoice.total }}</strong>.</p><p>Please transfer the amount by <strong>{{ invoice.due_on }}</strong>{% if invoice.iban %} to the account {{ invoice.iban }}{% endif %} with the reference <strong>{{ invoice.reference }}</strong>. The easiest way is to scan the QR code on the invoice with your banking app.</p><p>You can also find the invoice in the portal.</p>'.$button('View the invoice in the portal').'<p>Kind regards<br>{{ organisation.name }}</p>',
            ]],
            'payment_reminder' => ['reminder', 'guardians', ['de' => 'Zahlungserinnerung (vor Fälligkeit)', 'en' => 'Payment reminder (before due date)'], [
                'Freundliche Erinnerung: Rechnung {{ invoice.number }} ist am {{ invoice.due_on }} fällig',
                '<p>{{ salutation }}</p><p>eine kurze Erinnerung: Die Rechnung <strong>{{ invoice.number }}</strong> über <strong>{{ invoice.open }}</strong> ist am <strong>{{ invoice.due_on }}</strong> fällig. Verwendungszweck: <strong>{{ invoice.reference }}</strong>. Falls Sie bereits bezahlt haben, betrachten Sie diese Nachricht bitte als gegenstandslos.</p>'.$button('Rechnung im Portal ansehen').'<p>Mit freundlichen Grüßen<br>{{ organisation.name }}</p>',
            ], [
                'Friendly reminder: invoice {{ invoice.number }} is due on {{ invoice.due_on }}',
                '<p>{{ salutation }}</p><p>a short reminder: invoice <strong>{{ invoice.number }}</strong> for <strong>{{ invoice.open }}</strong> is due on <strong>{{ invoice.due_on }}</strong>. Reference: <strong>{{ invoice.reference }}</strong>. If you have already paid, please disregard this message.</p>'.$button('View the invoice in the portal').'<p>Kind regards<br>{{ organisation.name }}</p>',
            ]],
            'payment_overdue' => ['reminder', 'guardians', ['de' => 'Zahlungserinnerung (überfällig)', 'en' => 'Payment reminder (overdue)'], [
                'Zahlungserinnerung: Rechnung {{ invoice.number }}',
                '<p>{{ salutation }}</p><p>laut unseren Unterlagen ist die Rechnung <strong>{{ invoice.number }}</strong> (fällig am {{ invoice.due_on }}) noch offen: <strong>{{ invoice.open }}</strong>. Bitte überweisen Sie den Betrag mit dem Verwendungszweck <strong>{{ invoice.reference }}</strong>{% if invoice.iban %} auf das Konto {{ invoice.iban }}{% endif %}. Wenn sich die Zahlung mit dieser Nachricht überschnitten hat oder Sie Fragen haben, antworten Sie einfach auf diese E-Mail.</p>'.$button('Rechnung im Portal ansehen').'<p>Mit freundlichen Grüßen<br>{{ organisation.name }}</p>',
            ], [
                'Payment reminder: invoice {{ invoice.number }}',
                '<p>{{ salutation }}</p><p>according to our records, invoice <strong>{{ invoice.number }}</strong> (due on {{ invoice.due_on }}) is still open: <strong>{{ invoice.open }}</strong>. Please transfer the amount with the reference <strong>{{ invoice.reference }}</strong>{% if invoice.iban %} to the account {{ invoice.iban }}{% endif %}. If your payment crossed with this message or you have questions, simply reply to this e-mail.</p>'.$button('View the invoice in the portal').'<p>Kind regards<br>{{ organisation.name }}</p>',
            ]],
            'payment_confirmation' => ['invoice', 'guardians', ['de' => 'Zahlungsbestätigung', 'en' => 'Payment confirmation'], [
                'Zahlung erhalten: Rechnung {{ invoice.number }}',
                '<p>{{ salutation }}</p><p>vielen Dank! Wir haben Ihre Zahlung über <strong>{{ invoice.paid }}</strong> für die Rechnung <strong>{{ invoice.number }}</strong>{% if event %} ({{ event.title }}){% endif %} erhalten. Die Rechnung ist damit vollständig bezahlt.</p><p>Mit freundlichen Grüßen<br>{{ organisation.name }}</p>',
            ], [
                'Payment received: invoice {{ invoice.number }}',
                '<p>{{ salutation }}</p><p>thank you! We have received your payment of <strong>{{ invoice.paid }}</strong> for invoice <strong>{{ invoice.number }}</strong>{% if event %} ({{ event.title }}){% endif %}. The invoice is now paid in full.</p><p>Kind regards<br>{{ organisation.name }}</p>',
            ]],
            'waitlist_offer' => ['waitlist', 'guardians', ['de' => 'Platzangebot von der Warteliste', 'en' => 'Place offered from the waitlist'], [
                'Ein Platz ist frei: {{ event.title }} für {{ registration.child }}',
                '<p>{{ salutation }}</p><p>gute Nachrichten: Für <strong>{{ event.title }}</strong> ({{ event.starts_at }} – {{ event.ends_at }}) ist ein Platz frei geworden, und {{ registration.child }} ist als Nächstes an der Reihe.</p><p>Bitte nehmen Sie den Platz im Portal <strong>bis {{ registration.offer_until }}</strong> an oder sagen Sie ab. Ohne Antwort geht der Platz an die nächste Person auf der Warteliste.</p>'.$button('Platz annehmen oder absagen').'<p>Mit freundlichen Grüßen<br>{{ organisation.name }}</p>',
            ], [
                'A place is free: {{ event.title }} for {{ registration.child }}',
                '<p>{{ salutation }}</p><p>good news: a place has become free for <strong>{{ event.title }}</strong> ({{ event.starts_at }} – {{ event.ends_at }}), and {{ registration.child }} is next in line.</p><p>Please accept or decline the place in the portal <strong>by {{ registration.offer_until }}</strong>. Without an answer the place goes to the next person on the waitlist.</p>'.$button('Accept or decline the place').'<p>Kind regards<br>{{ organisation.name }}</p>',
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
