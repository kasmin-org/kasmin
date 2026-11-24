<?php

namespace App\Support\Install;

use App\Models\Automation;

/**
 * Standard automations (AT09, A02–A13), installed switched off: administrators review and activate them. Existing
 * automations with the same key are never changed.
 */
class AutomationDefaults
{
    /** @return array<string, array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            'waitlist_offers' => [
                'name' => ['de' => 'Freie Plätze der Warteliste anbieten', 'en' => 'Offer free places to the waitlist'],
                'description' => ['de' => 'Wird ein Platz frei, bekommt die nächste Person auf der Warteliste ein Angebot mit Frist.', 'en' => 'When a place becomes free, the next person on the waitlist gets an offer with a deadline.'],
                'action' => 'waitlist_offer', 'config' => ['offer_days' => 7], 'trigger' => 'on_place_freed', 'mode' => 'confirm',
            ],
            'waitlist_offers_daily' => [
                'name' => ['de' => 'Warteliste täglich prüfen', 'en' => 'Check the waitlist daily'],
                'description' => ['de' => 'Prüft täglich, ob Plätze frei sind (z. B. nach einer Erhöhung der Plätze), und bietet sie an.', 'en' => 'Checks daily whether places are free (e.g. after raising the capacity) and offers them.'],
                'action' => 'waitlist_offer', 'config' => ['offer_days' => 7], 'trigger' => 'daily', 'trigger_config' => ['time' => '08:00'], 'mode' => 'confirm',
            ],
            'offer_expiry' => [
                'name' => ['de' => 'Unbeantwortete Angebote verfallen lassen', 'en' => 'Expire unanswered offers'],
                'description' => ['de' => 'Nach Ablauf der Frist geht der Platz an die nächste Person.', 'en' => 'After the deadline the place goes to the next person.'],
                'action' => 'offer_expiry', 'trigger' => 'daily', 'trigger_config' => ['time' => '07:30'], 'mode' => 'automatic',
            ],
            'start_season' => [
                'name' => ['de' => 'Saison starten', 'en' => 'Start season'],
                'description' => ['de' => 'Übernimmt die Kinder der letzten Veranstaltung derselben Reihe, deren Alter noch passt; zu alte werden für die nächste Rolle oder den Austritt vorgeschlagen.', 'en' => 'Takes over the children of the last event of the same series whose age still fits; those too old are proposed for the next role or for leaving.'],
                'action' => 'start_season', 'config' => ['role' => 'child', 'next_role' => 'junior_counsellor'], 'trigger' => 'manual', 'mode' => 'manual',
            ],
            'deposit_invoices' => [
                'name' => ['de' => 'Anzahlungsrechnungen', 'en' => 'Deposit invoices'],
                'description' => ['de' => 'Erstellt und versendet die Rechnungen der Rate „deposit“ pro Familie.', 'en' => 'Creates and sends the invoices of the instalment "deposit" per family.'],
                'action' => 'invoice_run', 'config' => ['installment' => 'deposit', 'send' => true], 'trigger' => 'manual', 'mode' => 'confirm',
            ],
            'balance_invoices' => [
                'name' => ['de' => 'Restzahlungsrechnungen', 'en' => 'Balance invoices'],
                'description' => ['de' => 'Erstellt und versendet die Rechnungen der Rate „balance“ 90 Tage vor Beginn.', 'en' => 'Creates and sends the invoices of the instalment "balance" 90 days before the start.'],
                'action' => 'invoice_run', 'config' => ['installment' => 'balance', 'send' => true], 'trigger' => 'before_event', 'trigger_config' => ['days' => 90], 'mode' => 'confirm',
            ],
            'payment_reminders' => [
                'name' => ['de' => 'Zahlungserinnerungen', 'en' => 'Payment reminders'],
                'description' => ['de' => 'Erinnert vor und nach der Fälligkeit an offene Rechnungen, nach Freigabe.', 'en' => 'Reminds of open invoices before and after the due date, after approval.'],
                'action' => 'payment_reminders', 'trigger' => 'daily', 'trigger_config' => ['time' => '09:00'], 'mode' => 'confirm', 'active' => true,
            ],
            'payment_followup' => [
                'name' => ['de' => 'Plätze unbezahlter Rechnungen freigeben', 'en' => 'Release places of unpaid invoices'],
                'description' => ['de' => 'Schlägt 14 Tage nach der letzten Erinnerung vor, den Platz freizugeben.', 'en' => 'Proposes to release the place 14 days after the last reminder.'],
                'action' => 'payment_followup', 'config' => ['days' => 14], 'trigger' => 'daily', 'trigger_config' => ['time' => '09:15'], 'mode' => 'confirm',
            ],
            'invoice_corrections' => [
                'name' => ['de' => 'Rechnungen korrigieren', 'en' => 'Correct invoices'],
                'description' => ['de' => 'Schlägt Korrekturen vor, wenn sich Geschwister, Preise oder Anmeldungen geändert haben.', 'en' => 'Proposes corrections when siblings, prices or registrations have changed.'],
                'action' => 'invoice_corrections', 'config' => ['send' => true], 'trigger' => 'daily', 'trigger_config' => ['time' => '09:30'], 'mode' => 'confirm', 'active' => true,
            ],
            'membership_invoices' => [
                'name' => ['de' => 'Mitgliedsbeiträge', 'en' => 'Membership fees'],
                'description' => ['de' => 'Erstellt und versendet die Rechnungen für den Mitgliedsbeitrag des Jahres pro Familie.', 'en' => 'Creates and sends the invoices for the membership fee of the year per family.'],
                'action' => 'membership_invoices', 'config' => ['send' => true], 'trigger' => 'manual', 'mode' => 'confirm',
            ],
            'safety_sheet_reminders' => [
                'name' => ['de' => 'Erinnerungen Sicherheitsblatt', 'en' => 'Safety sheet reminders'],
                'description' => ['de' => 'Erinnert Familien mit fehlendem oder unbestätigtem Sicherheitsblatt alle 7 Tage, höchstens 4-mal, für Veranstaltungen in den nächsten 60 Tagen; danach Liste an das Team.', 'en' => 'Reminds families with a missing or unconfirmed safety sheet every 7 days, at most 4 times, for events in the next 60 days; then a list to the team.'],
                'action' => 'safety_sheet_reminder', 'config' => ['form' => 'safety_sheet', 'interval_days' => 7, 'max' => 4, 'window_days' => 60], 'trigger' => 'daily', 'trigger_config' => ['time' => '18:00'], 'mode' => 'confirm',
            ],
            'event_information' => [
                'name' => ['de' => 'Informationen vor der Veranstaltung', 'en' => 'Information before the event'],
                'description' => ['de' => 'Sendet 14 Tage vor Beginn die Vorlage „Informationen zur Veranstaltung“ an alle Familien.', 'en' => 'Sends the template "Event information" to all families 14 days before the start.'],
                'action' => 'send_template', 'config' => ['template' => 'event_information', 'statuses' => ['registered', 'confirmed'], 'to' => 'guardians'], 'trigger' => 'before_event', 'trigger_config' => ['days' => 14], 'mode' => 'confirm',
            ],
            'close_event' => [
                'name' => ['de' => 'Veranstaltung abschließen', 'en' => 'Close the event'],
                'description' => ['de' => 'Markiert angemeldete Teilnehmende einen Tag nach dem Ende als „teilgenommen“.', 'en' => 'Marks registered participants as "attended" one day after the end.'],
                'action' => 'close_event', 'trigger' => 'after_event', 'trigger_config' => ['days' => 1], 'mode' => 'confirm',
            ],
            'age_notice' => [
                'name' => ['de' => 'Hinweis: Kind wird 14', 'en' => 'Notice: child turns 14'],
                'description' => ['de' => 'Meldet dem Team einmal, wenn ein Kind 14 wird (z. B. für Nachwuchs-Betreuung oder eigenen Zugang).', 'en' => 'Tells the team once when a child turns 14 (e.g. for a junior counsellor role or an own login).'],
                'action' => 'age_notice', 'config' => ['age' => 14, 'window_days' => 30], 'trigger' => 'daily', 'trigger_config' => ['time' => '07:00'], 'mode' => 'automatic',
            ],
        ];
    }

    public function install(): void
    {
        foreach (self::definitions() as $key => $definition) {
            Automation::query()->firstOrCreate(['key' => $key], $definition + ['is_standard' => true, 'active' => false]);
        }
    }
}
