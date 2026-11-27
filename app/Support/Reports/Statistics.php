<?php

namespace App\Support\Reports;

use App\Models\Event;
use App\Models\EventSeries;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Participation;
use App\Models\Person;
use App\Support\Lists\ListTable;
use Illuminate\Support\Collection;

/**
 * Statistics (AD60–AD65). Only aggregated figures; the age groups list names because they are meant as group lists
 * for activities.
 */
class Statistics
{
    /**
     * Occupying participations of an event, optionally limited to roles.
     *
     * @param  list<string>  $roles
     * @return Collection<int, Participation>
     */
    private function participants(Event $event, array $roles = []): Collection
    {
        return Participation::query()->where('event_id', $event->id)->whereIn('status', ['registered', 'confirmed', 'attended', 'offered'])
            ->when($roles !== [], fn ($q) => $q->whereIn('role_key', $roles))->with('person')->get()
            ->filter(fn (Participation $p) => $p->person !== null);
    }

    /**
     * Age distribution at the start of the event (AD62).
     *
     * @param  list<string>  $roles
     * @return array<int|string, int> age => count ("?" without date of birth)
     */
    public function ageDistribution(Event $event, array $roles = []): array
    {
        $reference = $event->ageReferenceDate();
        $ages = [];
        foreach ($this->participants($event, $roles) as $p) {
            $age = $p->person?->ageAt($reference);
            $key = $age ?? '?';
            $ages[$key] = ($ages[$key] ?? 0) + 1;
        }
        uksort($ages, fn ($a, $b) => $a === '?' ? 1 : ($b === '?' ? -1 : $a <=> $b));

        return $ages;
    }

    /**
     * Splits the participants into $count age groups of about equal size (AD63), youngest group first, with the
     * birth-date and age boundaries of each group.
     *
     * @param  list<string>  $roles
     * @return list<array{from: string|null, to: string|null, min_age: int|null, max_age: int|null, size: int, names: list<string>}>
     */
    public function ageGroups(Event $event, int $count, array $roles = []): array
    {
        $reference = $event->ageReferenceDate();
        $groups = [];
        foreach ($this->ageGroupSlices($event, $count, $roles) as $slice) {
            $people = array_map(fn (Participation $p) => $p->person, $slice);
            $first = $people[0];
            $last = $people[count($people) - 1];
            $groups[] = [
                'from' => $last?->birth_date?->toDateString(), 'to' => $first?->birth_date?->toDateString(),
                'min_age' => $first?->ageAt($reference), 'max_age' => $last?->ageAt($reference),
                'size' => count($people), 'names' => array_map(fn (?Person $p) => (string) $p?->fullName(), $people),
            ];
        }

        return $groups;
    }

    /** Fields of a person the export of the age groups can hold; nothing confidential. */
    public const AGE_GROUP_FIELDS = ['group', 'first_name', 'last_name', 'age', 'birth_date', 'gender', 'role'];

    /**
     * The members of the age groups as a list for XLSX, CSV and PDF (AD63): one row per person with the chosen
     * fields, in the order of the groups (youngest first).
     *
     * @param  list<string>  $roles
     * @param  list<string>  $fields  out of AGE_GROUP_FIELDS; unknown ones are ignored, none = all
     */
    public function ageGroupList(Event $event, int $count, array $roles = [], array $fields = []): ListTable
    {
        $fields = array_values(array_intersect(self::AGE_GROUP_FIELDS, $fields)) ?: self::AGE_GROUP_FIELDS;
        $reference = $event->ageReferenceDate();
        $rows = [];
        foreach ($this->ageGroupSlices($event, $count, $roles) as $i => $slice) {
            foreach ($slice as $participation) {
                $person = $participation->person;
                if ($person === null) {
                    continue;
                }
                $values = [
                    'group' => __('reports.group').' '.($i + 1),
                    'first_name' => $person->first_name, 'last_name' => $person->last_name,
                    'age' => (string) ($person->ageAt($reference) ?? ''),
                    'birth_date' => (string) $person->birth_date?->isoFormat('L'),
                    'gender' => $person->getAttribute('gender') ? (string) __('admin.genders.'.$person->getAttribute('gender')) : '',
                    'role' => (string) __('events.roles.'.$participation->role_key),
                ];
                $rows[] = array_map(fn (string $field) => (string) $values[$field], $fields);
            }
        }

        return new ListTable(__('reports.age_groups'), array_map(fn (string $field) => (string) __('reports.fields.'.$field), $fields), $rows);
    }

    /**
     * @param  list<string>  $roles
     * @return list<list<Participation>> the groups, youngest first; people without a date of birth are left out
     */
    private function ageGroupSlices(Event $event, int $count, array $roles): array
    {
        $count = max(2, min(8, $count));
        /** @var list<Participation> $people */
        $people = $this->participants($event, $roles)->filter(fn (Participation $p) => $p->person?->birth_date !== null)
            ->sortBy(fn (Participation $p) => [-1 * (int) $p->person?->birth_date?->timestamp, $p->person?->last_name, $p->person?->first_name])->values()->all();
        $total = count($people);
        $groups = [];
        $start = 0;
        for ($i = 0; $i < $count && $start < $total; $i++) {
            $size = intdiv($total - $start + ($count - $i) - 1, $count - $i); // spread the remainder over the first groups
            $slice = array_slice($people, $start, $size);
            // Keep children born on the same day in one group.
            while ($start + $size < $total && $people[$start + $size]->person?->birth_date?->toDateString() === $slice[count($slice) - 1]->person?->birth_date?->toDateString()) {
                $slice[] = $people[$start + $size];
                $size++;
            }
            $start += $size;
            $groups[] = $slice;
        }

        return $groups;
    }

    /**
     * Income, costs and result per participant (AD64).
     *
     * @return array{income: int, discounts: int, open: int, costs: int, costs_by_category: array<string, int>, result: int, participants: int, per_participant: int|null}
     */
    public function costs(Event $event): array
    {
        $invoices = Invoice::query()->where('event_id', $event->id)->whereNotIn('status', ['draft', 'cancelled', 'corrected'])->get();
        $income = (int) $invoices->sum('total_cents');
        $discounts = (int) InvoiceLine::query()->whereIn('invoice_id', $invoices->pluck('id'))->sum('discount_cents');
        $costs = $event->costs()->get();
        $byCategory = $costs->groupBy(fn ($c) => $c->category ?? 'other')->map(fn ($g) => (int) $g->sum('amount_cents'))->all();
        $participants = $this->participants($event)->count();
        $result = $income - (int) $costs->sum('amount_cents');

        return [
            'income' => $income, 'discounts' => $discounts, 'open' => (int) $invoices->sum(fn (Invoice $i) => max(0, $i->openCents())),
            'costs' => (int) $costs->sum('amount_cents'), 'costs_by_category' => $byCategory, 'result' => $result,
            'participants' => $participants, 'per_participant' => $participants > 0 ? intdiv((int) $costs->sum('amount_cents'), $participants) : null,
        ];
    }

    /**
     * Sibling and role discounts of an event (AD65): how many lines got which discount, and the sums.
     *
     * @return array{sibling: array<int, array{count: int, cents: int}>, role: array{count: int, cents: int}}
     */
    public function discounts(Event $event): array
    {
        $lines = InvoiceLine::query()->whereHas('invoice', fn ($q) => $q->where('event_id', $event->id)->whereNotIn('status', ['draft', 'cancelled', 'corrected']))
            ->where('discount_cents', '>', 0)->get();
        $sibling = [];
        foreach ($lines->where('discount_kind', 'sibling') as $line) {
            $position = preg_match('/(\d+)\./', $line->description, $m) === 1 ? (int) $m[1] : (preg_match('/no\. (\d+)/', $line->description, $m) === 1 ? (int) $m[1] : 0);
            $sibling[$position]['count'] = ($sibling[$position]['count'] ?? 0) + 1;
            $sibling[$position]['cents'] = ($sibling[$position]['cents'] ?? 0) + $line->discount_cents;
        }
        ksort($sibling);
        $role = $lines->where('discount_kind', 'role');

        return ['sibling' => $sibling, 'role' => ['count' => $role->count(), 'cents' => (int) $role->sum('discount_cents')]];
    }

    /**
     * Participants per year and series, returning vs. new (AD60).
     *
     * @return list<array{series: string, year: int, title: string, participants: int, returning: int, new: int}>
     */
    public function years(): array
    {
        $rows = [];
        foreach (EventSeries::query()->orderBy('name')->get() as $series) {
            $seen = [];
            foreach (Event::query()->where('event_series_id', $series->id)->orderBy('starts_at')->get() as $event) {
                $ids = Participation::query()->where('event_id', $event->id)->whereIn('status', ['registered', 'confirmed', 'attended'])->pluck('person_id')->unique()->all();
                $returning = count(array_intersect($ids, $seen));
                $rows[] = ['series' => $series->name, 'year' => (int) $event->starts_at->format('Y'), 'title' => $event->title, 'participants' => count($ids), 'returning' => $returning, 'new' => count($ids) - $returning];
                $seen = array_values(array_unique(array_merge($seen, $ids)));
            }
        }

        return $rows;
    }
}
