<?php

namespace App\Support\Automations\Actions;

use App\Models\Automation;
use App\Models\Event;
use App\Models\Participation;
use App\Support\Automations\Action;
use App\Support\Mail\Composer;
use App\Support\Mail\Routing;

/**
 * GP11, A06: tells families that a child has moved up on the waitlist. The first position is told by the registration mail.
 */
class WaitlistPositions implements Action
{
    public function plan(Automation $automation, ?Event $event): array
    {
        $items = [];
        $waiting = Participation::query()->where('status', 'waitlist')->whereHas('event', fn ($q) => $q->where('starts_at', '>', now()))->when($event, fn ($q) => $q->where('event_id', $event?->id))->with('event', 'person')->get();
        foreach ($waiting as $p) {
            $position = $p->waitlistPosition();
            if ($p->notified_position === null || $position === null) {
                $p->forceFill(['notified_position' => $position])->saveQuietly(); // known from the registration mail

                continue;
            }
            if ($position < $p->notified_position) {
                $items[] = ['key' => 'participation:'.$p->id, 'label' => (string) __('automations.items.position', ['name' => $p->person?->fullName(), 'event' => $p->event?->title, 'position' => $position])];
            }
        }

        return $items;
    }

    public function execute(Automation $automation, array $item, ?Event $event): string
    {
        $p = Participation::query()->with('event', 'person')->find((int) substr($item['key'], 14));
        $person = $p?->person;
        $position = $p?->waitlistPosition();
        if ($p === null || $person === null || $position === null || $position >= (int) $p->notified_position) {
            return 'skipped';
        }
        $p->forceFill(['notified_position' => $position])->saveQuietly();
        foreach (Routing::recipients('waitlist_position', $person, $p) as $recipient) {
            app(Composer::class)->queue('waitlist_position', $recipient, ['event' => $p->event, 'participation' => $p, 'household' => $person->households()->first(), 'children' => [$person]]);
        }

        return 'sent';
    }
}
