<?php

namespace App\Support\Mail;

use App\Models\Consent;
use App\Models\EventSeries;
use App\Models\Group;
use App\Models\Participation;
use App\Models\Person;

/**
 * Who does not want which mails (CO03, CO05); the latest choice counts. Mails about own registrations and invoices are always sent.
 */
class Optouts
{
    public static function latest(int $personId, string $kind, ?int $subject = null): ?bool
    {
        $granted = Consent::query()->where('person_id', $personId)->where('kind', $kind)->where('subject_id', $subject)->latest('id')->value('granted');

        return $granted === null ? null : (bool) $granted;
    }

    public static function set(Person $person, string $kind, bool $granted, string $source, ?int $subject = null): void
    {
        if (self::latest($person->id, $kind, $subject) !== $granted) {
            $name = $kind === 'optout_series' ? EventSeries::query()->whereKey($subject)->value('name') : ($kind === 'optout_group' ? Group::query()->whereKey($subject)->value('name') : null);
            Consent::query()->create(['person_id' => $person->id, 'kind' => $kind, 'granted' => $granted, 'source' => $source, 'subject_type' => $subject !== null ? substr($kind, 7) : null, 'subject_id' => $subject, 'text' => $name]);
        }
    }

    /**
     * People who do not want these mails.
     *
     * @return list<int>
     */
    public static function people(string $kind, ?int $subject = null): array
    {
        return array_values(Consent::query()->where('kind', $kind)->where('subject_id', $subject)->orderBy('id')->get()->groupBy('person_id')
            ->filter(fn ($rows) => $rows->last()?->granted === false)->keys()->map(fn ($id) => (int) $id)->all());
    }

    /** @return list<array{type: string, id: int, name: string, on: bool}> event series and groups of the person and its children */
    public static function topics(Person $person): array
    {
        $ids = [$person->id, ...$person->wards()->pluck('people.id')->all()];
        $topics = [];
        $series = Participation::query()->whereIn('person_id', $ids)->with('event')->get()->filter(fn (Participation $p) => $p->visibleInPortal($person))->map(fn (Participation $p) => $p->event?->event_series_id)->filter()->unique();
        foreach (EventSeries::query()->whereIn('id', $series)->get() as $s) {
            $topics[] = ['type' => 'series', 'id' => $s->id, 'name' => $s->name, 'on' => self::latest($person->id, 'optout_series', $s->id) !== false];
        }
        foreach (Group::query()->whereHas('people', fn ($q) => $q->whereIn('people.id', $ids))->get() as $g) {
            $topics[] = ['type' => 'group', 'id' => $g->id, 'name' => $g->name, 'on' => self::latest($person->id, 'optout_group', $g->id) !== false];
        }

        return $topics;
    }
}
