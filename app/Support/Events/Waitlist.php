<?php

namespace App\Support\Events;

use App\Models\Event;
use App\Models\EventRole;
use App\Models\Group;
use App\Models\GroupWaitlistEntry;
use App\Models\Participation;
use App\Models\Person;
use App\Support\Lists\EventLists;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Order of waitlists by the rules of the event role or group (WL02): criteria one after the other, the registration date
 * decides in the end. Without rules only the registration date counts.
 *
 * @phpstan-type Place array{entry: Participation|GroupWaitlistEntry, position: int, flags: list<string>, previous: bool, camps: int, since: Carbon}
 */
class Waitlist
{
    /** flags: priority flags; previous: took part in the last event; camps: number of past events. */
    public const CRITERIA = ['flags', 'previous', 'camps'];

    public const FLAGS = ['sibling', 'social', 'other'];

    /**
     * @param  array<array-key, mixed>|null  $order
     * @return list<string>
     */
    public static function criteria(?array $order): array
    {
        return array_values(array_unique(array_intersect(array_filter($order ?? [], 'is_string'), self::CRITERIA)));
    }

    /** @return list<Place> */
    public function ofEvent(Event $event, string $roleKey): array
    {
        $criteria = self::criteria($event->roles()->where('role_key', $roleKey)->first()?->waitlist_order);
        $waiting = Participation::query()->where('event_id', $event->id)->where('role_key', $roleKey)->where('status', 'waitlist')->with('person.households')->get();

        return $this->sort($waiting->all(), $criteria, $event, fn (Participation $p) => $p->registered_at);
    }

    /** @return list<Place> */
    public function ofGroup(Group $group): array
    {
        $waiting = GroupWaitlistEntry::query()->where('group_id', $group->id)->where('status', 'waiting')->with('person.households')->get();

        return $this->sort($waiting->all(), self::criteria($group->waitlist_order), null, fn (GroupWaitlistEntry $e) => $e->listed_at);
    }

    public function position(Participation $participation): ?int
    {
        $event = $participation->event;
        if ($event === null || $participation->status !== 'waitlist') {
            return null;
        }
        foreach ($this->ofEvent($event, $participation->role_key) as $place) {
            if ($place['entry']->id === $participation->id) {
                return $place['position'];
            }
        }

        return null;
    }

    /** The rule as text, so the order stays transparent (AD31). */
    public static function ruleText(?EventRole $role): string
    {
        return self::text(self::criteria($role?->waitlist_order));
    }

    /** @param  list<string>  $criteria */
    public static function text(array $criteria): string
    {
        return implode(', ', array_map(fn (string $c) => (string) __('events.waitlist_criteria.'.$c), [...$criteria, 'date']));
    }

    /**
     * @template T of Participation|GroupWaitlistEntry
     *
     * @param  array<array-key, T>  $entries
     * @param  list<string>  $criteria
     * @param  \Closure(T): Carbon  $since
     * @return list<Place>
     */
    private function sort(array $entries, array $criteria, ?Event $event, \Closure $since): array
    {
        $history = $criteria === [] ? collect() : $this->history(array_map(fn ($e) => $e->person_id, $entries), $event);
        $last = in_array('previous', $criteria, true) ? $this->lastEvents($event) : [];
        $siblings = $event !== null && in_array('flags', $criteria, true) ? $this->familiesTakingPart($event) : [];
        $places = [];
        foreach ($entries as $entry) {
            $person = $entry->person;
            $flags = array_values(array_intersect(self::FLAGS, $entry->waitlist_flags ?? []));
            if ($person !== null && ! in_array('sibling', $flags, true) && $person->households->pluck('id')->intersect($siblings)->isNotEmpty()) {
                $flags[] = 'sibling';
            }
            $past = $history->get($entry->person_id) ?? collect();
            $places[] = [
                'entry' => $entry, 'position' => 0, 'flags' => $flags,
                'previous' => $past->contains(fn (Participation $p) => in_array($p->event_id, $last, true)),
                'camps' => $past->unique('event_id')->count(), 'since' => $since($entry),
            ];
        }
        $key = function (array $place) use ($criteria): array {
            $key = array_map(fn (string $c) => match ($c) {
                'flags' => -count($place['flags']), 'previous' => $place['previous'] ? 0 : 1, default => -$place['camps'],
            }, $criteria);

            return [...$key, $place['since']->getTimestamp(), $place['entry']->id];
        };
        usort($places, fn (array $a, array $b) => $key($a) <=> $key($b));
        foreach ($places as $i => $place) {
            $places[$i]['position'] = $i + 1;
        }

        return $places;
    }

    /**
     * @param  array<array-key, int>  $people
     * @return Collection<array-key, \Illuminate\Database\Eloquent\Collection<int, Participation>> past participations by person
     */
    private function history(array $people, ?Event $event): Collection
    {
        return Participation::query()->whereIn('person_id', $people)->whereIn('status', EventLists::STATUSES)
            ->whereHas('event', fn ($q) => $q->where('is_template', false)->where('ends_at', '<', $event->starts_at ?? now())
                ->when($event?->event_series_id, fn ($q, $series) => $q->where('event_series_id', $series)))
            ->get()->groupBy('person_id')->toBase();
    }

    /** @return list<int> the last event of the same series; for groups the last event of every series */
    private function lastEvents(?Event $event): array
    {
        if ($event !== null) {
            $id = $event->event_series_id === null ? null : Event::query()->where('event_series_id', $event->event_series_id)->whereKeyNot($event->id)
                ->where('starts_at', '<', $event->starts_at)->whereNotIn('status', ['draft', 'cancelled'])->orderByDesc('starts_at')->value('id');

            return $id === null ? [] : [(int) $id];
        }

        return array_values(Event::query()->whereNotNull('event_series_id')->where('ends_at', '<', now())->whereNotIn('status', ['draft', 'cancelled'])->orderByDesc('starts_at')->get()
            ->unique('event_series_id')->map(fn (Event $e) => $e->id)->all());
    }

    /** @return list<int> families with a child that takes part; own registrations stay private (PE05a) */
    private function familiesTakingPart(Event $event): array
    {
        $people = Participation::query()->where('event_id', $event->id)->whereIn('status', EventRole::OCCUPYING)->get()->reject(fn (Participation $p) => $p->isOwn())->pluck('person_id');

        return array_values(Person::query()->whereIn('id', $people)->with('households')->get()->flatMap(fn (Person $p) => $p->households->pluck('id'))->unique()->map(fn ($id) => (int) $id)->all());
    }
}
