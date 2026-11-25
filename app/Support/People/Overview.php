<?php

namespace App\Support\People;

use App\Models\Consent;
use App\Models\Household;
use App\Models\Invoice;
use App\Models\OutgoingMail;
use App\Models\Person;
use App\Models\User;
use App\Support\Auth\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Everything about a person (AD20) or a family (AD22) on one page: each block only for people with the right for it.
 * Blocks the viewer may not see are null. No answers of forms – those stay with the safety role.
 */
class Overview
{
    /** @return array<string, mixed> */
    public function person(Person $person, User $viewer): array
    {
        $link = fn (Person $p) => ['name' => $p->fullName(), 'url' => url('/admin/people/'.$p->id)];
        $user = $person->user;

        return [
            'guardians' => $person->guardians()->get()->map($link)->all(),
            'children' => $person->wards()->get()->map($link)->all(),
            'families' => $person->households()->get()->map(fn (Household $h) => ['name' => $h->name, 'url' => url('/admin/households/'.$h->id.'/edit')])->all(),
            'events' => ! Permissions::allows($viewer, 'events') ? null : $person->participations()->with('event')->get()->filter(fn ($p) => $p->event !== null)->sortByDesc(fn ($p) => $p->event?->starts_at)
                ->map(fn ($p) => ['title' => (string) $p->event?->title, 'role' => $p->role_key, 'status' => $p->status,
                    'url' => Permissions::allowsEvent($viewer, $p->event_id, 'edit') ? url('/admin/events/'.$p->event_id.'/edit') : null])->values()->all(),
            'groups' => $person->groups()->orderBy('name')->pluck('name')->all(),
            'memberships' => $person->memberships()->with('type')->get()->filter(fn ($m) => $m->getAttribute('ends_on') === null || Carbon::parse($m->getAttribute('ends_on'))->isFuture())
                ->map(fn ($m) => (string) ($m->type?->label() ?? '–'))->values()->all(),
            'roles' => $person->activeRoleKeys(),
            'invoices' => ! $viewer->canViewFinance() ? null : $this->invoices(Invoice::query()->where(fn ($q) => $q->where('payer_id', $person->id)->orWhereHas('lines', fn ($l) => $l->where('person_id', $person->id)))),
            'consents' => ! Permissions::allows($viewer, 'privacy') ? null : Consent::query()->where('person_id', $person->id)->latest('id')->limit(10)->get()
                ->map(fn (Consent $c) => ['kind' => $c->kind, 'granted' => (bool) $c->granted, 'at' => $c->created_at])->all(),
            'login' => [
                'address' => $user?->email,
                'last' => $user?->getAttribute('last_login_at'),
                'second_factor' => $user?->two_factor_confirmed_at !== null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function household(Household $household, User $viewer): array
    {
        return [
            'invoices' => ! $viewer->canViewFinance() ? null : $this->invoices(Invoice::query()->where('household_id', $household->id)),
            'mails' => ! Permissions::allows($viewer, 'mailings') ? null : OutgoingMail::query()->where('household_id', $household->id)->latest('id')->limit(15)->get()
                ->map(fn (OutgoingMail $m) => ['subject' => $m->subject, 'to' => $m->to_address, 'status' => $m->status, 'at' => $m->getAttribute('sent_at') ?? $m->created_at,
                    'url' => url('/admin/outgoing-mails?tableAction=view&tableActionRecord='.$m->id)])->all(),
            'emergency' => ! Permissions::allows($viewer, 'safety') ? null : $household->emergencyContacts()->get()
                ->map(fn ($c) => ['name' => $c->name, 'relation' => $c->relation, 'phone' => $c->phone, 'note' => $c->note])->all(),
        ];
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return list<array<string, mixed>>
     */
    private function invoices(Builder $query): array
    {
        return array_values($query->where('status', '!=', 'draft')->latest('id')->limit(20)->get()->map(fn (Invoice $i) => [
            'number' => $i->number, 'total' => Invoice::money($i->total_cents, $i->currency), 'open' => Invoice::money($i->openCents(), $i->currency),
            'status' => $i->isOverdue() ? 'overdue' : $i->status, 'url' => url('/admin/invoices/'.$i->id),
        ])->all());
    }
}
