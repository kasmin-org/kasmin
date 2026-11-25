<?php

namespace App\Support\Events;

use App\Models\Event;
use App\Models\Participation;
use Illuminate\Support\Collection;

/**
 * The bus of an event (ST06). Everybody who takes part travels by bus; adults can be moved to cars when the bus is
 * too small. Children always go by bus.
 */
class Bus
{
    /** Registrations that need a seat. */
    public const STATUSES = ['registered', 'confirmed', 'attended'];

    public const ADULT_AGE = 18;

    /** @return array{booked: bool, seats: int|null, riders: int, cars: int, free: int|null, over: int, adults_on_bus: int} */
    public static function status(Event $event): array
    {
        $people = self::travellers($event);
        $riders = $people->filter(fn (Participation $p) => self::travel($p) === 'bus');
        $seats = $event->getAttribute('bus_seats') !== null ? (int) $event->getAttribute('bus_seats') : null;
        $booked = (bool) $event->getAttribute('bus_booked');

        return [
            'booked' => $booked, 'seats' => $seats, 'riders' => $riders->count(), 'cars' => $people->count() - $riders->count(),
            'free' => $booked && $seats !== null ? max(0, $seats - $riders->count()) : null,
            'over' => $booked && $seats !== null ? max(0, $riders->count() - $seats) : 0,
            'adults_on_bus' => $riders->filter(fn (Participation $p) => self::adult($p, $event))->count(),
        ];
    }

    /** One line for the team, e.g. "Bus: 52 of 50 seats – 2 too many. 9 adults are on the bus and could go by car." */
    public static function summary(Event $event): ?string
    {
        $s = self::status($event);
        if (! $s['booked']) {
            return null;
        }
        if ($s['seats'] === null) {
            return (string) __('events.bus.no_seats', ['riders' => $s['riders'], 'cars' => $s['cars']]);
        }

        return $s['over'] > 0
            ? (string) __('events.bus.over', ['riders' => $s['riders'], 'seats' => $s['seats'], 'over' => $s['over'], 'adults' => $s['adults_on_bus'], 'cars' => $s['cars']])
            : (string) __('events.bus.ok', ['riders' => $s['riders'], 'seats' => $s['seats'], 'free' => $s['free'], 'cars' => $s['cars']]);
    }

    public static function travel(Participation $participation): string
    {
        return $participation->getAttribute('travel') === 'car' ? 'car' : 'bus';
    }

    /** Only adults can go by car (age at the start of the event; without a date of birth: team roles count as adults). */
    public static function adult(Participation $participation, ?Event $event = null): bool
    {
        $event ??= $participation->event;
        $age = $participation->person?->ageAt($event?->starts_at);

        return $age !== null ? $age >= self::ADULT_AGE : ! in_array($participation->role_key, ['child', 'junior_counsellor'], true);
    }

    /** Sets how somebody travels. Children stay on the bus: returns false and changes nothing. */
    public static function set(Participation $participation, string $travel): bool
    {
        $travel = $travel === 'car' ? 'car' : 'bus';
        if ($travel === 'car' && ! self::adult($participation)) {
            return false;
        }
        if (self::travel($participation) !== $travel) {
            $participation->forceFill(['travel' => $travel])->save();
        }

        return true;
    }

    /** @return Collection<int, Participation> */
    private static function travellers(Event $event): Collection
    {
        return Participation::query()->where('event_id', $event->id)->whereIn('status', self::STATUSES)->with('person')->get();
    }
}
