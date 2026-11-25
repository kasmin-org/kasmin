<?php

namespace App\Support\Privacy;

use App\Models\Consent;
use App\Models\FormResponse;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\OutgoingMail;
use App\Models\Participation;
use App\Models\Person;
use App\Support\Forms\Forms;
use App\Support\Forms\RegistrationAnswers;

/**
 * Copy of the data of a person and the children it is a legal guardian of (N03, Art. 15 and 20 GDPR).
 */
class DataExport
{
    /** @return array<string, mixed> */
    public function for(Person $person): array
    {
        $households = $person->households()->with('members', 'emergencyContacts')->get();
        $own = $households->filter(fn (Household $h) => $h->members->contains(fn (Person $m) => $m->is($person) && $m->getRelationValue('pivot')?->getAttribute('role') === 'guardian'));
        $invoices = Invoice::query()->where('status', '!=', 'draft')->where(fn ($q) => $q->where('payer_id', $person->id)->orWhereIn('household_id', $own->pluck('id')))->with('lines', 'payments')->get();

        return [
            'exported_at' => now()->toIso8601String(),
            'person' => $this->person($person, $person),
            'children' => $person->wards()->get()->map(fn (Person $c) => $this->person($c, $person))->values()->all(),
            'families' => $own->map(fn (Household $h) => [
                'name' => $h->name, 'street' => $h->getAttribute('street'), 'postal_code' => $h->getAttribute('postal_code'), 'city' => $h->getAttribute('city'), 'country' => $h->getAttribute('country'),
                'members' => $h->members->map(fn (Person $m) => ['name' => $m->fullName(), 'role' => $m->getRelationValue('pivot')?->getAttribute('role')])->values()->all(),
                'emergency_contacts' => $h->emergencyContacts->map(fn ($c) => ['name' => $c->getAttribute('name'), 'relation' => $c->getAttribute('relation'), 'phone' => $c->getAttribute('phone')])->values()->all(),
            ])->values()->all(),
            'invoices' => $invoices->map(fn (Invoice $i) => ['number' => $i->number, 'type' => $i->type, 'status' => $i->status, 'issued_on' => $i->issued_on?->toDateString(), 'due_on' => $i->due_on?->toDateString(), 'total' => $i->total_cents / 100, 'paid' => $i->paid_cents / 100, 'currency' => $i->currency,
                'lines' => $i->lines->map(fn ($l) => ['description' => $l->description, 'amount' => $l->amount_cents / 100])->values()->all(),
                'payments' => $i->payments->map(fn ($p) => ['paid_on' => $p->paid_on->toDateString(), 'amount' => $p->amount_cents / 100])->values()->all()])->values()->all(),
            'mails' => OutgoingMail::query()->where('person_id', $person->id)->where('kind', '!=', 'notification')->latest('id')->get()->map(fn (OutgoingMail $m) => ['sent_at' => $m->sent_at?->toIso8601String(), 'to' => $m->to_address, 'subject' => $m->subject])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function person(Person $person, Person $viewer): array
    {
        $answers = app(RegistrationAnswers::class);
        $forms = app(Forms::class);

        return [
            'first_name' => $person->first_name, 'last_name' => $person->last_name, 'birth_date' => $person->birth_date?->toDateString(), 'gender' => $person->getAttribute('gender'), 'language' => $person->locale,
            'contacts' => $person->contactPoints()->get()->map(fn ($c) => ['type' => $c->type, 'value' => $c->value])->values()->all(),
            'memberships' => $person->memberships()->with('type')->get()->map(fn (Membership $m) => ['type' => $m->type?->label(), 'from' => (string) $m->getAttribute('starts_on'), 'to' => $m->getAttribute('ends_on') !== null ? (string) $m->getAttribute('ends_on') : null])->values()->all(),
            'groups' => $person->groups()->pluck('name')->all(),
            'registrations' => $person->participations()->with('event', 'person')->get()->filter(fn (Participation $p) => $p->visibleInPortal($viewer))->map(function (Participation $p) use ($answers) {
                $form = $p->event !== null ? $answers->form($p->event, $p->role_key) : null;

                return ['event' => $p->event?->title, 'role' => $p->role_key, 'status' => $p->status, 'registered_at' => $p->registered_at->toIso8601String(), 'answers' => $form !== null ? $answers->values($p, $form) : []];
            })->values()->all(),
            // Only forms that still apply; from the age limit on, legal guardians no longer see the safety sheet (SF20a).
            'forms' => FormResponse::query()->where('person_id', $person->id)->with('form')->orderBy('id')->get()
                ->filter(fn (FormResponse $r) => $r->form !== null && ($person->is($viewer) || $forms->status($r->form, $person) !== 'not_applicable'))
                ->map(fn (FormResponse $r) => ['form' => $r->form?->label(), 'saved_at' => $r->created_at->toIso8601String(), 'confirmed_at' => $r->confirmed_at?->toIso8601String(), 'values' => $r->values])->values()->all(),
            'consents' => Consent::query()->where('person_id', $person->id)->orderBy('id')->get()->map(fn (Consent $c) => ['kind' => $c->kind, 'granted' => $c->granted, 'at' => $c->created_at?->toIso8601String(), 'source' => $c->getAttribute('source'), 'text' => $c->getAttribute('text')])->values()->all(),
        ];
    }
}
