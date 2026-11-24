<?php

namespace App\Support\Events;

use App\Models\Event;
use App\Models\Participation;
use App\Models\Person;
use App\Models\RegistrationRound;
use App\Support\Audit\Audit;
use App\Support\Lists\EventLists;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Registration rounds (RR01–RR03). Without rounds for a role, registration works as before.
 */
class Rounds
{
    /** May guardians and participants take a place directly now? Otherwise they go to the waitlist. */
    public static function directRegistration(Event $event, string $roleKey): bool
    {
        $rounds = RegistrationRound::query()->where('event_id', $event->id)->where('role_key', $roleKey)->get();

        return $rounds->isEmpty() || $rounds->contains(fn (RegistrationRound $r) => $r->kind === 'open' && $r->active());
    }

    /** May free places be offered to the waitlist now? */
    public static function waitlistMoves(Event $event, string $roleKey): bool
    {
        $rounds = RegistrationRound::query()->where('event_id', $event->id)->where('role_key', $roleKey)->get();

        return $rounds->isEmpty() || $rounds->contains(fn (RegistrationRound $r) => in_array($r->kind, ['waitlist', 'open'], true) && $r->started());
    }

    /**
     * People who get an offer in this round, in order, as many as there are free places.
     *
     * @return Collection<int, Person>
     */
    public function candidates(RegistrationRound $round): Collection
    {
        $event = $round->event;
        $role = $event?->roles()->where('role_key', $round->role_key)->first();
        if ($event === null || $role === null || $event->event_series_id === null || ! in_array($round->kind, ['returning', 'selection'], true)) {
            return collect();
        }
        $past = Participation::query()->whereIn('status', EventLists::STATUSES)
            ->whereHas('event', fn ($q) => $q->where('event_series_id', $event->event_series_id)->where('ends_at', '<', $event->starts_at)->where('id', '!=', $event->id))
            ->with('event', 'person.households', 'person.guardians')->get();
        $previous = Event::query()->where('event_series_id', $event->event_series_id)->where('starts_at', '<', $event->starts_at)->whereKeyNot($event->id)->orderByDesc('starts_at')->first();
        // Everyone who already has a registration is left out, also those who declined; people on the waitlist get the offer.
        $known = Participation::query()->where('event_id', $event->id)->where(fn ($q) => $q->where('status', '!=', 'waitlist')->orWhere('role_key', '!=', $round->role_key))->pluck('person_id')->all();

        $people = [];
        foreach ($past->groupBy('person_id') as $personId => $history) {
            $person = $history->first()?->person;
            if ($person === null || $person->getAttribute('anonymised_at') !== null || in_array($personId, $known, true) || ! $role->allowsAge($person->ageAt($event->ageReferenceDate()))) {
                continue;
            }
            $lastYear = $previous !== null && $history->contains(fn (Participation $p) => $p->event_id === $previous->id && ($round->kind === 'selection' || $p->role_key === $round->role_key));
            $asChild = $history->where('role_key', 'child')->unique('event_id')->count();
            $fits = $round->kind === 'returning' ? $lastYear : $asChild >= $round->min_events;
            if (! $fits || ($round->group_id !== null && ! $person->groups()->whereKey($round->group_id)->exists())) {
                continue;
            }
            $people[] = ['person' => $person, 'last' => $lastYear ? 0 : 1, 'count' => -$asChild, 'since' => (string) $history->min('registered_at')];
        }
        usort($people, fn (array $a, array $b) => [$a['last'], $a['count'], $a['since'], $a['person']->id] <=> [$b['last'], $b['count'], $b['since'], $b['person']->id]);
        $free = $role->bookableLeft();

        return collect(array_map(fn (array $p) => $p['person'], $free === null ? $people : array_slice($people, 0, $free)));
    }

    /** Offers a place until a deadline; null if there is no place any more. */
    public function offer(Event $event, Person $person, string $roleKey, Carbon $until, ?string $mode = null, ?string $reason = null): ?Participation
    {
        return DB::transaction(function () use ($event, $person, $roleKey, $until, $mode, $reason) {
            $role = $event->roles()->where('role_key', $roleKey)->lockForUpdate()->first();
            if ($role === null || ($role->bookableLeft() ?? 1) < 1) {
                return null;
            }
            $p = Participation::query()->firstOrNew(['event_id' => $event->id, 'person_id' => $person->id, 'role_key' => $roleKey]);
            if ($p->exists && ! in_array($p->status, $reason === null ? ['waitlist'] : ['waitlist', 'cancelled', 'declined'], true)) {
                return null;
            }
            $p->fill(['status' => 'offered', 'offer_expires_at' => $until, 'offer_mode' => $mode, 'offer_reason' => $reason, 'registered_at' => $p->registered_at ?? now(), 'cancelled_at' => null, 'cancel_reason' => null])->save();
            Audit::record('participation.offered', $p, null, ['until' => $until->toIso8601String(), 'reason' => $reason]);

            return $p;
        });
    }
}
