<?php

namespace App\Support\Automations;

use App\Models\Household;
use App\Models\Participation;
use App\Models\Person;
use Illuminate\Support\Collection;

/**
 * Groups participations by family, so a family gets one mail listing all its children (MM03), sent to the legal
 * guardians, or to the person itself if it has none (adults, MM02).
 */
class Families
{
    /**
     * @param  Collection<int, Participation>  $participations
     * @return Collection<string, array{household: Household|null, children: list<Person>, recipients: list<Person>, participations: list<Participation>}>
     */
    public static function group(Collection $participations, string $to = 'guardians'): Collection
    {
        $participations = (new \Illuminate\Database\Eloquent\Collection($participations->all()))->loadMissing('person.guardians', 'person.households');
        /** @var Collection<string, array{household: Household|null, children: list<Person>, recipients: list<Person>, participations: list<Participation>}> $result */
        $result = new Collection;
        foreach ($participations as $p) {
            $person = $p->person;
            if ($person === null) {
                continue;
            }
            $household = $p->isOwn() ? null : $person->households->first(); // own registrations are private (PE05a)
            $key = $household !== null ? 'household:'.$household->id : 'person:'.$person->id;
            $entry = $result->get($key, ['household' => $household, 'children' => [], 'recipients' => [], 'participations' => []]);
            $entry['children'][] = $person;
            $entry['participations'][] = $p;
            $recipients = match ($to) {
                'person' => [$person],
                'both' => $p->isOwn() ? [$person] : array_merge($person->guardians->all(), [$person]),
                default => $p->isOwn() ? [$person] : ($person->guardians->all() ?: [$person]),
            };
            foreach ($recipients as $r) {
                if (! collect($entry['recipients'])->contains('id', $r->id)) {
                    $entry['recipients'][] = $r;
                }
            }
            $result->put($key, $entry);
        }

        return $result;
    }

    /** @param  array{household: Household|null, children: list<Person>, recipients: list<Person>}  $family */
    public static function label(array $family): string
    {
        $children = implode(', ', array_map(fn (Person $p) => $p->fullName(), $family['children']));

        return ($family['household']->name ?? $children).($family['household'] ? ' ('.$children.')' : '');
    }
}
