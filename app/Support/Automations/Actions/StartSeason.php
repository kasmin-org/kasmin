<?php

namespace App\Support\Automations\Actions;

use App\Models\Automation;
use App\Models\Event;
use App\Models\Participation;
use App\Models\Person;
use App\Support\Automations\Action;
use App\Support\Events\RegistrationException;
use App\Support\Events\Registrations;
use App\Support\Mail\Notifier;

/**
 * A02 "start season": children of the previous event of the same series are registered for the new event if their
 * age still fits (config: role, default child); those who are too old are proposed for the next role (config:
 * next_role, default junior_counsellor) or for leaving – as a notice to the team.
 */
class StartSeason implements Action
{
    public function plan(Automation $automation, ?Event $event): array
    {
        $items = [];
        foreach ($this->candidates($automation, $event) as $c) {
            $items[] = [
                'key' => $c['kind'].':'.$c['person']->id,
                'label' => __('automations.items.season_'.$c['kind'], ['name' => $c['person']->fullName(), 'age' => $c['age']]),
            ];
        }

        return $items;
    }

    public function execute(Automation $automation, array $item, ?Event $event): string
    {
        [$kind, $id] = explode(':', $item['key']);
        $person = Person::query()->find((int) $id);
        if ($event === null || $person === null) {
            return 'skipped';
        }
        if ($kind === 'register') {
            try {
                $p = app(Registrations::class)->register($event, $person, (string) $automation->setting('role', 'child'), $person->guardians()->first(), force: true);

                return 'registered: '.$p->status;
            } catch (RegistrationException $e) {
                return 'skipped: '.$e->getMessage();
            }
        }
        app(Notifier::class)->event('registrations', $item['label'].' – '.$event->title, url('/admin/people/'.$person->id));

        return 'team notified';
    }

    /** @return list<array{kind: string, person: Person, age: int|null}> */
    private function candidates(Automation $automation, ?Event $event): array
    {
        if ($event === null) {
            return [];
        }
        $previous = Event::query()->where('event_series_id', $event->event_series_id)->whereNotNull('event_series_id')
            ->where('starts_at', '<', $event->starts_at)->orderByDesc('starts_at')->first();
        if ($previous === null) {
            return [];
        }
        $roleKey = (string) $automation->setting('role', 'child');
        $role = $event->roles()->where('role_key', $roleKey)->first();
        $next = $event->roles()->where('role_key', (string) $automation->setting('next_role', 'junior_counsellor'))->first();
        $already = Participation::query()->where('event_id', $event->id)->whereNotIn('status', ['cancelled', 'declined'])->pluck('person_id')->all();
        $result = [];
        $people = Participation::query()->where('event_id', $previous->id)->where('role_key', $roleKey)
            ->whereIn('status', ['registered', 'confirmed', 'attended'])->whereNotIn('person_id', $already)->with('person')->get();
        foreach ($people as $p) {
            if ($p->person === null) {
                continue;
            }
            $age = $p->person->ageAt($event->ageReferenceDate());
            $kind = match (true) {
                $role !== null && $role->allowsAge($age) => 'register',
                $next !== null && $next->allowsAge($age) => 'propose',
                default => 'leaving',
            };
            $result[] = ['kind' => $kind, 'person' => $p->person, 'age' => $age];
        }

        return $result;
    }
}
