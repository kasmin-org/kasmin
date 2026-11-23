<?php

namespace App\Support\Automations\Actions;

use App\Models\Automation;
use App\Models\Event;
use App\Models\Participation;
use App\Support\Audit\Audit;
use App\Support\Automations\Action;
use App\Support\Mail\Composer;
use App\Support\Mail\Notifier;

/**
 * A05: free places go to the next people on the waitlist as an offer with a deadline; the legal guardians accept
 * or decline in the portal. Config: offer_days (default 7).
 */
class WaitlistOffer implements Action
{
    public function plan(Automation $automation, ?Event $event): array
    {
        $events = $event !== null ? collect([$event]) : Event::query()->whereIn('status', ['open', 'full'])->where('starts_at', '>', now())->get();
        $days = (int) $automation->setting('offer_days', 7);
        $items = [];
        foreach ($events as $e) {
            foreach ($e->roles as $role) {
                $free = $role->bookableLeft();
                if ($free === null || $free < 1) {
                    continue;
                }
                $next = Participation::query()->where('event_id', $e->id)->where('role_key', $role->role_key)->where('status', 'waitlist')
                    ->orderBy('registered_at')->orderBy('id')->limit($free)->with('person')->get();
                foreach ($next as $p) {
                    $items[] = [
                        'key' => 'participation:'.$p->id,
                        'label' => __('automations.items.offer', ['name' => $p->person?->fullName(), 'event' => $e->title, 'position' => $p->waitlistPosition()]),
                        'details' => __('automations.items.offer_until', ['date' => now()->addDays($days)->isoFormat('L')]),
                    ];
                }
            }
        }

        return $items;
    }

    public function execute(Automation $automation, array $item, ?Event $event): string
    {
        $p = Participation::query()->with('event.roles', 'person.guardians', 'person.households')->find((int) substr($item['key'], 14));
        $person = $p?->person;
        $e = $p?->event;
        if ($p === null || $p->status !== 'waitlist' || $e === null || $person === null) {
            return 'skipped: no longer on the waitlist';
        }
        $role = $e->roles->firstWhere('role_key', $p->role_key);
        if ($role === null || ($role->bookableLeft() ?? 1) < 1) {
            return 'skipped: no free place';
        }
        $p->fill(['status' => 'offered', 'offer_expires_at' => now()->addDays((int) $automation->setting('offer_days', 7))->endOfDay()])->save();
        Audit::record('participation.offered', $p, null, ['until' => $p->offer_expires_at?->toIso8601String()]);
        foreach ($person->guardians->all() ?: [$person] as $recipient) {
            app(Composer::class)->queue('waitlist_offer', $recipient, [
                'event' => $e, 'participation' => $p, 'household' => $person->households->first(), 'children' => [$person],
            ]);
        }
        app(Notifier::class)->event('waitlist', __('automations.notify.offered', ['name' => $person->fullName(), 'event' => $e->title]), url('/admin/events/'.$p->event_id.'/edit'));

        return 'offered';
    }
}
