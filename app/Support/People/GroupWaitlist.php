<?php

namespace App\Support\People;

use App\Models\Group;
use App\Models\GroupWaitlistEntry;
use App\Models\Person;
use App\Support\Audit\Audit;
use App\Support\Events\RegistrationException;
use App\Support\Events\Waitlist;
use App\Support\Mail\Composer;
use App\Support\Mail\Routing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Waitlists of manual groups (WL01, WL03): list, offer with a deadline, accept or decline.
 */
class GroupWaitlist
{
    public function __construct(private readonly Waitlist $waitlist) {}

    public function add(Group $group, Person $person, ?string $note = null): GroupWaitlistEntry
    {
        if ($group->kind !== 'manual' || $group->people()->whereKey($person->id)->exists()
            || GroupWaitlistEntry::query()->where('group_id', $group->id)->where('person_id', $person->id)->whereIn('status', GroupWaitlistEntry::ACTIVE)->exists()) {
            throw RegistrationException::because('already');
        }

        return GroupWaitlistEntry::query()->create(['group_id' => $group->id, 'person_id' => $person->id, 'listed_at' => now(), 'waitlist_note' => $note]);
    }

    /** Free places; null without a limit. */
    public function free(Group $group): ?int
    {
        return $group->capacity === null ? null : max(0, $group->capacity - $group->people()->count() - GroupWaitlistEntry::query()->where('group_id', $group->id)->where('status', 'offered')->count());
    }

    public function position(GroupWaitlistEntry $entry): ?int
    {
        foreach ($entry->group === null ? [] : $this->waitlist->ofGroup($entry->group) as $place) {
            if ($place['entry']->id === $entry->id) {
                return $place['position'];
            }
        }

        return null;
    }

    public function offer(GroupWaitlistEntry $entry, Carbon $until): bool
    {
        $offered = DB::transaction(function () use ($entry, $until) {
            $group = Group::query()->whereKey($entry->group_id)->lockForUpdate()->firstOrFail();
            $fresh = GroupWaitlistEntry::query()->findOrFail($entry->id);
            if ($fresh->status !== 'waiting' || ($this->free($group) ?? 1) < 1) {
                return false;
            }
            $entry->fill(['status' => 'offered', 'offer_expires_at' => $until])->save();

            return true;
        });
        $person = $entry->person;
        if ($offered && $person !== null) {
            Audit::record('group.place_offered', $entry->group, null, ['person' => $person->id, 'until' => $until->toIso8601String()]);
            foreach (Routing::recipients('group_place_offer', $person) as $recipient) {
                app(Composer::class)->queue('group_place_offer', $recipient, ['group' => $entry->group, 'offer_until' => $until, 'children' => [$person], 'household' => $person->households()->first()]);
            }
        }

        return $offered;
    }

    public function answer(GroupWaitlistEntry $entry, bool $accept): void
    {
        if ($entry->status !== 'offered' || ($entry->offer_expires_at !== null && $entry->offer_expires_at->isPast())) {
            throw RegistrationException::because('no_offer');
        }
        $entry->fill(['status' => $accept ? 'joined' : 'declined', 'offer_expires_at' => null])->save();
        if ($accept && $entry->group !== null) {
            GroupMembers::join($entry->group, $entry->person_id, 'waitlist');
        }
        Audit::record($accept ? 'group.place_accepted' : 'group.place_declined', $entry->group, null, ['person' => $entry->person_id]);
    }

    /** Offers without an answer; returns how many expired. */
    public function expire(): int
    {
        $expired = GroupWaitlistEntry::query()->where('status', 'offered')->where('offer_expires_at', '<', now())->get();
        foreach ($expired as $entry) {
            $entry->fill(['status' => 'declined', 'offer_expires_at' => null])->save();
        }

        return $expired->count();
    }
}
