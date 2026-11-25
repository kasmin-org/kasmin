<?php

namespace App\Support\Mail;

use App\Models\Event;
use App\Models\FormField;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\Participation;
use App\Models\Person;
use App\Support\Forms\FormValue;
use App\Support\Forms\RegistrationAnswers;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Builds the placeholder context of a mail (MT03). Only plain, non-confidential values are included (MT04):
 * names, ages, family, event dates and places, registration status and waitlist position, links.
 */
class Placeholders
{
    /** Age from which people are addressed formally ("Sie" and full name), MT05. */
    public const ADULT_AGE = 18;

    /**
     * @param  array{event?: Event|null, participation?: Participation|null, household?: Household|null, invoice?: Invoice|null, children?: list<Person>, login?: bool}  $about
     * @return array<string, mixed>
     */
    public function for(Person $recipient, string $locale, array $about = []): array
    {
        $children = $about['children'] ?? [];
        $context = [
            'recipient' => $this->person($recipient),
            'salutation' => $this->salutation($recipient, $locale),
            'family' => ['name' => $about['household']->name ?? ''],
            'children' => array_map(fn (Person $c) => $this->person($c), $children),
            'organisation' => ['name' => self::organisationName()],
            'event' => isset($about['event']) ? $this->event($about['event'], $locale) : null,
            'registration' => isset($about['participation']) ? $this->participation($about['participation'], $locale) : null,
            'invoice' => isset($about['invoice']) ? $this->invoice($about['invoice'], $locale) : null,
            'links' => [
                'portal' => route('portal'),
                'login' => ($about['login'] ?? true) ? $this->loginLink($recipient) : route('login'),
                'preferences' => URL::temporarySignedRoute('mail.preferences', now()->addDays(180), ['person' => $recipient->public_id]),
            ],
        ];

        return $context;
    }

    /** @return array<string, mixed> */
    public function eventValues(Event $event, string $locale): array
    {
        return $this->event($event, $locale);
    }

    /** @return array<string, mixed> complete example context for previews and validation */
    public static function sample(): array
    {
        return [
            'recipient' => ['first_name' => 'Alex', 'last_name' => 'Example', 'full_name' => 'Alex Example', 'age' => 40],
            'salutation' => 'Guten Tag Alex Example,',
            'family' => ['name' => 'Example'],
            'children' => [['first_name' => 'Kim', 'last_name' => 'Example', 'full_name' => 'Kim Example', 'age' => 10]],
            'organisation' => ['name' => 'Example Camp'],
            'event' => ['title' => 'Summer camp', 'starts_at' => '15.08.2027', 'ends_at' => '22.08.2027', 'location' => 'Example place', 'meeting_point' => 'Station'],
            'registration' => ['status' => 'Waitlist', 'waitlist_position' => 3, 'waitlist_total' => 9, 'child' => 'Kim Example', 'answers' => [], 'by_payment' => false, 'offer_until' => '20.03.2027 23:59'],
            'invoice' => ['number' => 'R17', 'total' => '250,00 €', 'open' => '250,00 €', 'paid' => '0,00 €', 'due_on' => '01.06.2027', 'reference' => 'RF72R17', 'iban' => 'AT61 1904 3002 3457 3201', 'overdue' => false, 'children' => 'Kim Example'],
            'links' => ['portal' => 'https://example.org/portal', 'login' => 'https://example.org/login/link/x', 'preferences' => 'https://example.org/mail/preferences'],
        ];
    }

    /** Gender-neutral salutation: adults formal with full name, children informal with first name (MT05). */
    public function salutation(Person $person, string $locale): string
    {
        $adult = ($person->ageAt() ?? self::ADULT_AGE) >= self::ADULT_AGE;
        if ($locale === 'de') {
            return $adult ? "Guten Tag {$person->fullName()}," : "Hallo {$person->first_name},";
        }

        return $adult ? "Hello {$person->fullName()}," : "Hello {$person->first_name},";
    }

    public static function organisationName(): string
    {
        try {
            return (string) (DB::table('system_meta')->where('key', 'organisation_name')->value('value') ?? config('app.name'));
        } catch (\Throwable) {
            return (string) config('app.name');
        }
    }

    /** @return array<string, mixed> */
    private function person(Person $person): array
    {
        return ['first_name' => $person->first_name, 'last_name' => $person->last_name, 'full_name' => $person->fullName(), 'age' => $person->ageAt()];
    }

    /** @return array<string, mixed> */
    private function event(Event $event, string $locale): array
    {
        $format = $locale === 'de' ? 'd.m.Y' : 'j F Y';

        return [
            'title' => $event->title,
            'starts_at' => $this->date($event->starts_at, $locale, $format),
            'ends_at' => $this->date($event->ends_at, $locale, $format),
            'location' => (string) $event->getAttribute('location'),
            'meeting_point' => (string) $event->getAttribute('meeting_point'),
        ];
    }

    /** @return array<string, mixed> */
    private function invoice(Invoice $invoice, string $locale): array
    {
        $iban = strtoupper(str_replace(' ', '', (string) Settings::get('organisation_iban')));

        return [
            'number' => $invoice->number,
            'total' => Invoice::money($invoice->total_cents, $invoice->currency),
            'open' => Invoice::money($invoice->openCents(), $invoice->currency),
            'paid' => Invoice::money($invoice->paid_cents, $invoice->currency),
            'due_on' => $invoice->due_on ? $this->date($invoice->due_on, $locale, $locale === 'de' ? 'd.m.Y' : 'j F Y') : '',
            'reference' => $invoice->reference,
            'iban' => trim(chunk_split($iban, 4, ' ')),
            'overdue' => $invoice->isOverdue(),
            'children' => $invoice->lines->pluck('person')->filter()->unique('id')->map(fn (Person $p) => $p->fullName())->implode(', '),
        ];
    }

    /** @return array<string, string> non-confidential answers to the questions of the event (FB04) */
    private function answers(Participation $p): array
    {
        $service = app(RegistrationAnswers::class);
        $form = $p->event !== null ? $service->form($p->event, $p->role_key) : null;
        if ($form === null) {
            return [];
        }
        $values = $service->values($p, $form);
        $result = [];
        foreach ($form->fields->reject(fn (FormField $f) => $f->isConfidential()) as $field) {
            $result[$field->key] = FormValue::text($field, $values[$field->key] ?? null) ?? '';
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function participation(Participation $p, string $locale): array
    {
        $role = $p->event?->roles()->where('role_key', $p->role_key)->first();

        return [
            'status' => trans('events.status.'.$p->status, [], $locale),
            'waitlist_position' => $p->waitlistPosition(),
            'waitlist_total' => $role?->waitlistLength(),
            'child' => $p->person?->fullName(),
            'answers' => $this->answers($p),
            'by_payment' => $p->offer_mode === 'payment',
            'offer_until' => $p->offer_expires_at !== null ? $this->date($p->offer_expires_at, $locale, $locale === 'de' ? 'd.m.Y H:i' : 'j F Y, H:i') : '',
        ];
    }

    private function date(mixed $value, string $locale, string $format): string
    {
        $date = Carbon::parse($value);
        $date->setLocale($locale);

        return $date->translatedFormat($format);
    }

    private function loginLink(Person $person): string
    {
        if ($person->user === null) {
            return route('login');
        }

        // Long-lived, limited link, made when the mail is sent (L02–L04).
        return MailLinks::placeholder();
    }
}
