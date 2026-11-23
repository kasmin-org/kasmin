<?php

namespace App\Support\Automations\Actions;

use App\Models\Automation;
use App\Models\Event;
use App\Models\Participation;
use App\Support\Audit\Audit;
use App\Support\Automations\Action;

/**
 * A10: after the event, registered and confirmed participations become "attended" (history and statistics).
 */
class CloseEvent implements Action
{
    public function plan(Automation $automation, ?Event $event): array
    {
        if ($event === null || $event->ends_at->isFuture()) {
            return [];
        }

        return array_values(Participation::query()->where('event_id', $event->id)->whereIn('status', ['registered', 'confirmed'])->with('person')->get()
            ->map(fn (Participation $p) => ['key' => 'participation:'.$p->id, 'label' => (string) __('automations.items.attended', ['name' => $p->person?->fullName()])])
            ->all());
    }

    public function execute(Automation $automation, array $item, ?Event $event): string
    {
        $p = Participation::query()->find((int) substr($item['key'], 14));
        if ($p === null || ! in_array($p->status, ['registered', 'confirmed'], true)) {
            return 'skipped';
        }
        $p->fill(['status' => 'attended'])->save();
        Audit::record('participation.attended', $p);

        return 'attended';
    }
}
