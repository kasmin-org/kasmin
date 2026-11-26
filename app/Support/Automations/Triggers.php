<?php

namespace App\Support\Automations;

use App\Models\Automation;
use App\Models\Event;
use App\Models\Group;
use App\Models\Participation;
use App\Models\Person;

/**
 * Starts automations when something changes (AT01): status of a registration, member of a group, new person, changed
 * field. A problem in an automation never stops the change itself.
 */
class Triggers
{
    /** Automations that start other automations: deeper chains are cut, so they cannot run in circles. */
    private const MAX_DEPTH = 3;

    /** Fields of a person that can start an automation. */
    public const FIELDS = ['birth_date', 'first_name', 'last_name', 'gender'];

    private static int $depth = 0;

    private static bool $muted = false;

    /**
     * Without triggers, e.g. for imports and demo data.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    public static function muted(\Closure $callback): mixed
    {
        $before = self::$muted;
        self::$muted = true;
        try {
            return $callback();
        } finally {
            self::$muted = $before;
        }
    }

    public static function status(Participation $participation): void
    {
        $event = $participation->event;
        if ($event === null || $participation->person === null) {
            return;
        }
        self::fire('on_status', $participation->person, $event, ['participation_id' => $participation->id], fn (Automation $a) => in_array($participation->status, (array) $a->triggerSetting('statuses', []), true)
            && (blank($a->triggerSetting('series')) || (int) $a->triggerSetting('series') === (int) $event->event_series_id)
            && (blank($a->triggerSetting('roles')) || in_array($participation->role_key, (array) $a->triggerSetting('roles'), true)));
    }

    public static function group(string $trigger, Group $group, int $personId): void
    {
        $person = Person::query()->find($personId);
        if ($person !== null) {
            self::fire($trigger, $person, null, [], fn (Automation $a) => (int) $a->triggerSetting('group') === $group->id);
        }
    }

    public static function personCreated(Person $person): void
    {
        self::fire('on_person_created', $person, null, [], fn () => true);
    }

    public static function personChanged(Person $person): void
    {
        if ($person->wasChanged('anonymised_at') || $person->getAttribute('anonymised_at') !== null) {
            return;
        }
        $changed = array_intersect(self::FIELDS, array_keys($person->getChanges()));
        if ($changed !== []) {
            self::fire('on_person_changed', $person, null, [], fn (Automation $a) => array_intersect($changed, (array) $a->triggerSetting('fields', [])) !== []);
        }
    }

    /**
     * @param  array<string, int>  $context
     * @param  \Closure(Automation): bool  $fits
     */
    private static function fire(string $trigger, Person $person, ?Event $event, array $context, \Closure $fits): void
    {
        if (self::$muted || self::$depth >= self::MAX_DEPTH) {
            return;
        }
        self::$depth++;
        try {
            foreach (Automation::query()->where('active', true)->where('trigger', $trigger)->get() as $automation) {
                if ($fits($automation)) {
                    app(Engine::class)->trigger($automation, $trigger, $event, context: ['person_id' => $person->id] + $context);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        } finally {
            self::$depth--;
        }
    }
}
