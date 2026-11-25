<?php

namespace App\Support\Events;

use App\Models\Event;
use App\Models\EventRole;
use App\Models\Person;
use App\Support\Audit\Audit;
use App\Support\Auth\Permissions;
use App\Support\Automations\Engine;
use App\Support\Mail\Composer;
use Illuminate\Support\Facades\DB;

/**
 * Reserve places that are not needed go to the waitlist (WL06): by hand, or at a date of the event – by default only as a
 * reminder to all administrators.
 */
class ReservePlaces
{
    /** Gives free reserve places to the waitlist; returns how many. */
    public function release(EventRole $role, int $count, string $reason): int
    {
        if (trim($reason) === '') {
            throw RegistrationException::because('reason');
        }
        $released = DB::transaction(function () use ($role, $count) {
            $locked = EventRole::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();
            $released = min($count, $locked->reserveLeft());
            if ($released > 0) {
                $locked->update(['reserve_places' => $locked->reserve_places - $released]);
            }

            return max(0, $released);
        });
        $event = $role->event;
        if ($released > 0 && $event !== null) {
            Audit::record('event.reserve_released', $event, null, ['role' => $role->role_key, 'places' => $released, 'reason' => $reason]);
            app(Engine::class)->placeFreed($event->load('roles'));
        }

        return $released;
    }

    /** Reminders and automatic releases that are due; returns the number of events. */
    public function tick(): int
    {
        $done = 0;
        foreach (Event::query()->whereNotNull('reserve_reminder_on')->whereNull('reserve_reminded_at')->whereDate('reserve_reminder_on', '<=', now()->toDateString())
            ->where('starts_at', '>', now())->whereIn('status', ['open', 'full', 'closed'])->with('roles')->get() as $event) {
            $event->forceFill(['reserve_reminded_at' => now()])->saveQuietly();
            $free = $event->roles->filter(fn (EventRole $r) => $r->capacity !== null && $r->reserveLeft() > 0);
            if ($free->isEmpty()) {
                continue;
            }
            $event->reserve_auto_release ? $free->each(fn (EventRole $r) => $this->release($r, $r->reserveLeft(), (string) __('events.reserve_auto_reason'))) : $this->remind($event, $free->all());
            $done++;
        }

        return $done;
    }

    /** @param  array<array-key, EventRole>  $roles */
    private function remind(Event $event, array $roles): void
    {
        foreach (Person::query()->whereIn('id', Permissions::administrators())->get() as $person) {
            $address = $person->primaryEmail();
            if ($address === null) {
                continue;
            }
            $locale = $person->locale;
            $list = implode('', array_map(fn (EventRole $r) => '<li>'.e(__('events.roles.'.$r->role_key, [], $locale)).': '.e(__('events.reserve_free', ['free' => $r->reserveLeft(), 'waiting' => $r->waitlistLength()], $locale)).'</li>', $roles));
            app(Composer::class)->queueRaw($address, $person->fullName(), (string) __('events.reserve_reminder_subject', ['event' => $event->title], $locale),
                '<p>'.e(__('events.reserve_reminder_text', ['event' => $event->title], $locale)).'</p><ul>'.$list.'</ul><p><a href="'.e(url('/admin/events/'.$event->id.'/edit')).'">'.e($event->title).'</a></p>', $locale);
        }
        Audit::record('event.reserve_reminder', $event, null, [], 'system');
    }
}
