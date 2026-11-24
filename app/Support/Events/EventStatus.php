<?php

namespace App\Support\Events;

use App\Models\Event;
use App\Support\Audit\Audit;
use Illuminate\Support\Carbon;

/**
 * Changes the status of events by date (EE02, PF04). Every date acts once, so a change by hand afterwards stays.
 */
class EventStatus
{
    public function tick(): int
    {
        $changed = 0;
        $now = now();
        foreach (Event::query()->whereNotIn('status', ['finished', 'archived'])->get() as $event) {
            $since = $event->status_checked_at ?? $event->created_at ?? $now;
            $status = $event->status;
            foreach ($this->steps($event) as [$date, $from, $to]) {
                if ($date !== null && $date->gt($since) && $date->lte($now) && in_array($status, $from, true)) {
                    $status = $to;
                }
            }
            if ($status !== $event->status) {
                Audit::record('event.status_by_date', $event, ['status' => [$event->status, $status]], null, 'system');
                $event->status = $status;
                $changed++;
            }
            $event->forceFill(['status_checked_at' => $now])->saveQuietly();
        }

        return $changed;
    }

    /** @return list<array{0: Carbon|null, 1: list<string>, 2: string}> */
    private function steps(Event $event): array
    {
        $steps = [
            [$event->announce_at, ['draft'], 'announced'],
            [$event->registration_opens_at, ['draft', 'announced'], 'open'],
            [$event->registration_closes_at, ['open'], 'closed'],
            [$event->starts_at, ['announced', 'open', 'closed'], 'running'],
            [$event->ends_at, ['announced', 'open', 'closed', 'running'], 'finished'],
        ];
        usort($steps, fn (array $a, array $b) => ($a[0]->timestamp ?? 0) <=> ($b[0]->timestamp ?? 0));

        return $steps;
    }
}
