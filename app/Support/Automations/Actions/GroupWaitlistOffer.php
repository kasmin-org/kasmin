<?php

namespace App\Support\Automations\Actions;

use App\Models\Automation;
use App\Models\Event;
use App\Models\Group;
use App\Models\GroupWaitlistEntry;
use App\Support\Automations\Action;
use App\Support\Events\Waitlist;
use App\Support\People\GroupWaitlist;

/**
 * Free places in groups with a waitlist go to the next people as an offer with a deadline (WL01, WL03). Config:
 * offer_days (default 14).
 */
class GroupWaitlistOffer implements Action
{
    public function __construct(private readonly Waitlist $waitlist, private readonly GroupWaitlist $groups) {}

    public function plan(Automation $automation, ?Event $event): array
    {
        $items = [];
        $until = now()->addDays((int) $automation->setting('offer_days', 14));
        foreach (Group::query()->where('kind', 'manual')->where('has_waitlist', true)->whereNotNull('capacity')->orderBy('name')->get() as $group) {
            $free = (int) $this->groups->free($group);
            $next = array_filter($this->waitlist->ofGroup($group), fn (array $place) => $place['entry']->skipped_at === null);
            foreach (array_slice($next, 0, $free) as $place) {
                $items[] = [
                    'key' => 'entry:'.$place['entry']->id,
                    'label' => (string) __('automations.items.offer', ['name' => $place['entry']->person?->fullName(), 'event' => $group->name, 'position' => $place['position']]),
                    'details' => (string) __('automations.items.offer_until', ['date' => $until->isoFormat('L')]),
                ];
            }
        }

        return $items;
    }

    public function execute(Automation $automation, array $item, ?Event $event): string
    {
        $entry = GroupWaitlistEntry::query()->with('group', 'person')->find((int) substr($item['key'], 6));

        return $entry !== null && $entry->skipped_at === null && $this->groups->offer($entry, now()->addDays((int) $automation->setting('offer_days', 14))->endOfDay()) ? 'offered' : 'skipped: no free place';
    }
}
