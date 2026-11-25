<?php

namespace App\Support\Events;

use App\Models\Event;
use App\Models\EventRole;
use App\Models\Participation;
use App\Models\Person;
use App\Support\Audit\Audit;
use App\Support\Automations\Engine;
use App\Support\Mail\Composer;
use App\Support\Mail\Notifier;
use App\Support\Mail\Routing;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Registers people for events: age rule, capacity, reserve places, waitlist (RG05, EE03, EE04, WL04–WL07).
 */
class Registrations
{
    /**
     * @param  bool  $force  team/automation registrations outside the registration period (age limits still apply)
     */
    public function register(Event $event, Person $person, string $roleKey = 'child', ?Person $registeredBy = null, bool $force = false): Participation
    {
        if (! $force && ! $event->isOpenForRegistration()) {
            throw RegistrationException::because('not_open');
        }
        $role = $event->roles()->where('role_key', $roleKey)->first();
        if ($role === null) {
            throw RegistrationException::because('no_role');
        }
        if (! $role->allowsAge($person->ageAt($event->ageReferenceDate()))) {
            throw RegistrationException::because('age');
        }

        $participation = DB::transaction(function () use ($event, $person, $role, $registeredBy, $force) {
            // Lock the role row so two registrations at the same moment cannot both take the last place.
            EventRole::query()->whereKey($role->id)->lockForUpdate()->first();

            $existing = Participation::query()->where('event_id', $event->id)->where('person_id', $person->id)->where('role_key', $role->role_key)->first();
            if ($existing !== null && ! in_array($existing->status, ['cancelled', 'declined'], true)) {
                throw RegistrationException::because('already');
            }

            $left = $role->bookableLeft();
            // In registration rounds, places are taken directly only in an open round (RR01); the team always can.
            $direct = $force || $registeredBy === null || Rounds::directRegistration($event, $role->role_key);
            $status = $direct && ($left === null || $left > 0) ? ($event->registration_binding ? 'registered' : 'requested') : 'waitlist';

            $participation = $existing ?? new Participation(['event_id' => $event->id, 'person_id' => $person->id, 'role_key' => $role->role_key]);
            $participation->fill(['status' => $status, 'registered_at' => now(), 'registered_by_id' => $registeredBy?->id, 'reserve_place' => false, 'reserve_reason' => null, 'cancelled_at' => null, 'cancel_reason' => null])->save();

            return $participation;
        });

        $this->confirm($participation->load(['event', 'person']));

        return $participation;
    }

    /**
     * Confirmation mail to the legal guardians (or the person itself) with status and waitlist position (A01), and an
     * admin notification (NT02).
     */
    private function confirm(Participation $participation): void
    {
        $person = $participation->person;
        $event = $participation->event;
        if ($person === null || $event === null) {
            return;
        }
        $recipients = Routing::recipients('registration_confirmation', $person, $participation);
        foreach ($recipients as $recipient) {
            app(Composer::class)->queue('registration_confirmation', $recipient, [
                'event' => $event, 'participation' => $participation, 'household' => $person->households()->first(), 'children' => [$person],
            ]);
        }
        if ($participation->isOwn() && Settings::get('own_registrations') === 'informed') {
            foreach ($person->guardians()->get() as $guardian) { // only that there is a registration (PE05a)
                app(Composer::class)->queue('own_registration_notice', $guardian, ['children' => [$person]]);
            }
        }
        app(Notifier::class)->event($participation->status === 'waitlist' ? 'waitlist' : 'registrations',
            __('events.notify_registration', ['name' => $person->fullName(), 'event' => $event->title, 'status' => __('events.status.'.$participation->status)]),
            url('/admin/events/'.$participation->event_id.'/edit'));
    }

    /**
     * Gives a free reserve place to someone on the waitlist (only administrators; reason required, WL05).
     */
    public function assignReservePlace(Participation $participation, string $reason): Participation
    {
        $role = EventRole::query()->where('event_id', $participation->event_id)->where('role_key', $participation->role_key)->firstOrFail();
        if (trim($reason) === '') {
            throw RegistrationException::because('reason');
        }
        if ($role->reserveLeft() < 1) {
            throw RegistrationException::because('no_reserve');
        }
        $participation->fill(['status' => 'registered', 'reserve_place' => true, 'reserve_reason' => $reason])->save();
        Audit::record('participation.reserve_place_assigned', $participation, null, ['reason' => $reason]);

        return $participation;
    }

    public function cancel(Participation $participation, ?string $reason = null): Participation
    {
        $freed = in_array($participation->status, EventRole::OCCUPYING, true);
        $participation->fill(['status' => 'cancelled', 'offer_expires_at' => null, 'cancelled_at' => now(), 'cancel_reason' => $reason !== null ? mb_substr($reason, 0, 250) : null])->save();
        if ($freed && $participation->event !== null) {
            app(Engine::class)->placeFreed($participation->event);
        }

        return $participation;
    }

    /** The legal guardians accept or decline an offered place (A05). */
    public function answerOffer(Participation $participation, bool $accept): Participation
    {
        if ($participation->status !== 'offered') {
            throw RegistrationException::because('no_offer');
        }
        if ($accept && $participation->offer_mode === 'payment') {
            throw RegistrationException::because('pay_to_accept');
        }
        if ($accept) {
            $participation->fill(['status' => $participation->event?->registration_binding === false ? 'requested' : 'registered', 'offer_expires_at' => null])->save();
            Audit::record('participation.offer_accepted', $participation);
            $this->confirm($participation->load(['event', 'person']));

            return $participation;
        }
        $participation->fill(['status' => 'declined', 'offer_expires_at' => null])->save();
        Audit::record('participation.offer_declined', $participation);
        if ($participation->event !== null) {
            app(Engine::class)->placeFreed($participation->event);
        }

        return $participation;
    }

    /**
     * Events a person can register for now, with the role that fits (age and who registers).
     *
     * @return list<array{event: Event, role: EventRole}>
     */
    public function openFor(Person $person, string $registeredBy = 'guardian'): array
    {
        $result = [];
        foreach (Event::query()->where('status', 'open')->orderBy('starts_at')->with('roles')->get() as $event) {
            foreach ($event->roles as $role) {
                if ($role->registered_by === $registeredBy && $role->allowsAge($person->ageAt($event->ageReferenceDate()))) {
                    $result[] = ['event' => $event, 'role' => $role];
                    break;
                }
            }
        }

        return $result;
    }
}
