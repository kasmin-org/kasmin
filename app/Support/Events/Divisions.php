<?php

namespace App\Support\Events;

use App\Models\Event;
use App\Models\EventDivision;
use App\Models\EventUnit;
use App\Models\Participation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Groups, rooms and duties within an event (EC04). The proposal spreads the people over the units: equal sizes,
 * mixed or separated by gender, mixed ages, team roles spread evenly, siblings apart or together – as set for the
 * division. It is a proposal: everybody can be moved by hand afterwards, and a new proposal never moves people who
 * are already placed unless asked to start again.
 */
class Divisions
{
    public const STATUSES = ['registered', 'confirmed', 'attended'];

    /** @param  array<string, mixed>  $options */
    public function create(Event $event, string $name, string $kind, int $units, ?int $capacity = null, array $options = []): EventDivision
    {
        $kind = in_array($kind, EventDivision::KINDS, true) ? $kind : 'groups';
        $division = EventDivision::query()->create([
            'event_id' => $event->id, 'name' => $name, 'kind' => $kind, 'options' => $options + EventDivision::DEFAULTS[$kind],
            'sort' => (int) EventDivision::query()->where('event_id', $event->id)->max('sort') + 1,
        ]);
        for ($i = 1; $i <= max(1, min(100, $units)); $i++) {
            $division->units()->create(['name' => __('events.divisions.unit_'.$kind, ['n' => $i]), 'capacity' => $capacity, 'sort' => $i]);
        }

        return $division;
    }

    /** One more group, room or shift at the end. */
    public function addUnit(EventDivision $division): EventUnit
    {
        $n = $division->units()->count() + 1;

        return $division->units()->create(['name' => __('events.divisions.unit_'.$division->kind, ['n' => $n]), 'sort' => (int) $division->units()->max('sort') + 1]);
    }

    /**
     * The people a division is for: everybody who takes part with one of its roles.
     *
     * @return Collection<int, Participation>
     */
    public function people(EventDivision $division): Collection
    {
        $roles = (array) $division->option('roles');

        return Participation::query()->where('event_id', $division->event_id)->whereIn('status', self::STATUSES)
            ->when($roles !== [], fn ($q) => $q->whereIn('role_key', $roles))->with('person.households', 'person.guardians')->get()
            ->filter(fn (Participation $p) => $p->person !== null)->sortBy(fn (Participation $p) => $p->person?->last_name.' '.$p->person?->first_name)->values();
    }

    /** @return array<int, int> participation id => unit id */
    public function placed(EventDivision $division): array
    {
        return DB::table('event_unit_members')->where('event_division_id', $division->id)->pluck('event_unit_id', 'participation_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Puts one person into a unit of the division, or takes it out (unit null). */
    public function move(EventDivision $division, Participation $participation, ?EventUnit $unit): void
    {
        abort_unless($participation->event_id === $division->event_id && ($unit === null || $unit->event_division_id === $division->id), 422);
        DB::table('event_unit_members')->where('event_division_id', $division->id)->where('participation_id', $participation->id)->delete();
        if ($unit !== null) {
            DB::table('event_unit_members')->insert(['event_division_id' => $division->id, 'event_unit_id' => $unit->id, 'participation_id' => $participation->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function clear(EventDivision $division): void
    {
        DB::table('event_unit_members')->where('event_division_id', $division->id)->delete();
    }

    /**
     * Places everybody who has no unit yet (or everybody, with $restart). Returns how many were placed.
     */
    public function propose(EventDivision $division, bool $restart = false): int
    {
        if ($restart) {
            $this->clear($division);
        }
        $event = $division->event ?? throw new \RuntimeException('Division without event.');
        $units = $division->units()->get();
        $people = $this->people($division);
        $placed = $this->placed($division);
        $facts = $people->mapWithKeys(fn (Participation $p) => [$p->id => $this->facts($p, $event)])->all();
        /** @var array<int, list<int>> $members unit id => participation ids */
        $members = $units->mapWithKeys(fn (EventUnit $u) => [$u->id => []])->all();
        foreach ($placed as $participationId => $unitId) {
            if (isset($members[$unitId], $facts[$participationId])) {
                $members[$unitId][] = $participationId;
            }
        }
        // Team roles first (one per unit before a second one), then the oldest: the hard cases get the free choice.
        $todo = $people->reject(fn (Participation $p) => isset($placed[$p->id]))
            ->sortBy(fn (Participation $p) => [$p->role_key === 'child' ? 1 : 0, -($facts[$p->id]['age'] ?? 0), $facts[$p->id]['gender'], $p->id])->values();
        /** @var list<int> $new */
        $new = [];
        foreach ($todo as $p) {
            $best = null;
            $bestCost = null;
            foreach ($units as $unit) {
                $cost = $this->cost($division, $unit, $members[$unit->id], $facts, $facts[$p->id]);
                if ($cost !== null && ($bestCost === null || $cost < $bestCost)) {
                    [$best, $bestCost] = [$unit, $cost];
                }
            }
            if ($best !== null) {
                $members[$best->id][] = $p->id;
                $new[] = $p->id;
            }
        }
        $byId = $people->keyBy('id');
        foreach ($this->improve($division, $members, $facts, $new) as $unitId => $ids) {
            foreach (array_intersect($ids, $new) as $id) {
                $this->move($division, $byId->get($id) ?? throw new \RuntimeException('Unknown registration.'), $units->firstWhere('id', $unitId));
            }
        }

        return count($new);
    }

    /**
     * What to check by hand: people without a unit, units over their size, mixed rooms, siblings not as wished.
     *
     * @return list<string>
     */
    public function warnings(EventDivision $division): array
    {
        $event = $division->event ?? throw new \RuntimeException('Division without event.');
        $people = $this->people($division)->keyBy('id');
        $placed = $this->placed($division);
        $warnings = [];
        $open = $people->keys()->reject(fn ($id) => isset($placed[$id]))->count();
        if ($open > 0) {
            $warnings[] = (string) trans_choice('events.divisions.warn_open', $open, ['count' => $open]);
        }
        foreach ($division->units()->get() as $unit) {
            $inside = $people->only(array_keys($placed, $unit->id, true));
            if ($unit->capacity !== null && $inside->count() > $unit->capacity) {
                $warnings[] = (string) __('events.divisions.warn_full', ['unit' => $unit->name, 'count' => $inside->count(), 'capacity' => $unit->capacity]);
            }
            $facts = $inside->map(fn (Participation $p) => $this->facts($p, $event));
            if ($division->option('separate_gender') && $facts->pluck('gender')->reject(fn ($g) => $g === '')->unique()->count() > 1) {
                $warnings[] = (string) __('events.divisions.warn_mixed', ['unit' => $unit->name]);
            }
            $families = $facts->flatMap(fn (array $f) => $f['families'])->countBy()->filter(fn (int $n) => $n > 1)->count();
            if ($division->option('siblings') === 'apart' && $families > 0) {
                $warnings[] = (string) __('events.divisions.warn_siblings', ['unit' => $unit->name]);
            }
        }

        return $warnings;
    }

    /** @return array{age: int|null, gender: string, role: string, families: list<string>} */
    public function facts(Participation $participation, Event $event): array
    {
        $person = $participation->person;
        $families = [];
        foreach ($person !== null ? $person->households : [] as $household) {
            $families[] = 'h'.$household->id;
        }
        foreach ($person !== null ? $person->guardians : [] as $guardian) {
            $families[] = 'g'.$guardian->id;
        }

        return ['age' => $person?->ageAt($event->starts_at), 'gender' => (string) $person?->getAttribute('gender'), 'role' => $participation->role_key, 'families' => $families];
    }

    /**
     * How bad it is to put the person into the unit; null = not possible.
     *
     * @param  list<int>  $inside
     * @param  array<int, array{age: int|null, gender: string, role: string, families: list<string>}>  $facts
     * @param  array{age: int|null, gender: string, role: string, families: list<string>}  $person
     */
    private function cost(EventDivision $division, EventUnit $unit, array $inside, array $facts, array $person): ?float
    {
        if ($unit->capacity !== null && count($inside) >= $unit->capacity) {
            return null;
        }
        $cost = count($inside) * 10.0 + $unit->sort * 0.001;
        foreach ($inside as $id) {
            $pair = $this->pair($division, $person, $facts[$id]);
            if ($pair === null) {
                return null;
            }
            $cost += $pair;
        }

        return $cost;
    }

    /**
     * How well two people fit into the same unit (higher = worse); null = they must not share one.
     *
     * @param  array{age: int|null, gender: string, role: string, families: list<string>}  $a
     * @param  array{age: int|null, gender: string, role: string, families: list<string>}  $b
     */
    private function pair(EventDivision $division, array $a, array $b): ?float
    {
        $sameGender = $a['gender'] !== '' && $a['gender'] === $b['gender'];
        if ($division->option('separate_gender') && $a['gender'] !== '' && $b['gender'] !== '' && ! $sameGender) {
            return null;
        }
        $cost = 0.0;
        if ($a['role'] === $b['role'] && $a['role'] !== 'child') {
            $cost += 100; // one junior counsellor or team member per unit before a second one
        }
        if ($division->option('balance_gender') && $sameGender) {
            $cost += 4;
        }
        if ($a['age'] !== null && $b['age'] !== null) {
            if ($division->option('balance_age')) {
                $cost += max(0, 3 - abs($a['age'] - $b['age'])); // the same age costs most
            } elseif ($division->kind === 'rooms') {
                $cost += abs($a['age'] - $b['age']) * 2; // rooms: similar ages together
            }
        }
        $related = array_intersect($a['families'], $b['families']) !== [];
        if ($related && $division->option('siblings') === 'apart') {
            $cost += 500;
        }
        if ($related && $division->option('siblings') === 'together') {
            $cost -= 500;
        }

        return $cost;
    }

    /**
     * Makes a proposal better by swapping two people of different units while that helps. Sizes stay as they are;
     * people who were placed before this proposal are not touched.
     *
     * @param  array<int, list<int>>  $members  unit id => participation ids
     * @param  array<int, array{age: int|null, gender: string, role: string, families: list<string>}>  $facts
     * @param  list<int>  $movable
     * @return array<int, list<int>>
     */
    private function improve(EventDivision $division, array $members, array $facts, array $movable): array
    {
        $with = function (int $id, array $others) use ($division, $facts): ?float {
            $sum = 0.0;
            foreach ($others as $other) {
                if ($other === $id) {
                    continue;
                }
                $pair = $this->pair($division, $facts[$id], $facts[$other]);
                if ($pair === null) {
                    return null;
                }
                $sum += $pair;
            }

            return $sum;
        };
        for ($pass = 0; $pass < 30; $pass++) {
            $changed = false;
            foreach ($movable as $p) {
                foreach ($movable as $q) {
                    $up = $this->unitOf($members, $p);
                    $uq = $this->unitOf($members, $q);
                    if ($p >= $q || $up === null || $uq === null || $up === $uq) {
                        continue;
                    }
                    $before = [$with($p, $members[$up]), $with($q, $members[$uq])];
                    $after = [$with($p, array_diff($members[$uq], [$q])), $with($q, array_diff($members[$up], [$p]))];
                    if (in_array(null, $after, true) || in_array(null, $before, true) || array_sum($after) >= array_sum($before) - 0.0001) {
                        continue;
                    }
                    $members[$up] = [...array_values(array_diff($members[$up], [$p])), $q];
                    $members[$uq] = [...array_values(array_diff($members[$uq], [$q])), $p];
                    $changed = true;
                }
            }
            if (! $changed) {
                break;
            }
        }

        return $members;
    }

    /** @param  array<int, list<int>>  $members */
    private function unitOf(array $members, int $participationId): ?int
    {
        foreach ($members as $unitId => $ids) {
            if (in_array($participationId, $ids, true)) {
                return $unitId;
            }
        }

        return null;
    }
}
