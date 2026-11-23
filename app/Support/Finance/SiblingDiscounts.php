<?php

namespace App\Support\Finance;

use App\Models\Event;
use App\Models\EventRole;
use App\Models\Participation;
use Illuminate\Support\Collection;

/**
 * Sibling and role discounts of one family in one event (SD01–SD09).
 *
 * - staggered: discount per sibling position (2nd child 1×, 3rd child 2× …) up to the cap position;
 * - all: every child gets the same discount once at least N children take part;
 * - order: the oldest child pays the full price (default), alternatively highest price or registration order;
 * - a participant with a role discount (e.g. junior counsellor) gets that instead, but still counts as a sibling.
 */
class SiblingDiscounts
{
    /**
     * @param  Collection<int, Participation>  $participations  participations of one family in the event
     * @return array<int, array{position: int, discount: int, kind: ?string}> keyed by participation id
     */
    public function compute(Event $event, Collection $participations): array
    {
        $roles = $event->roles->keyBy('role_key');
        $counting = $participations->filter(fn (Participation $p) => ($roles[$p->role_key] ?? null)->counts_as_sibling ?? false);
        $ordered = match ($event->sibling_order) {
            'highest_price' => $counting->sortByDesc(fn (Participation $p) => $roles[$p->role_key]->price_cents ?? 0),
            'registration' => $counting->sortBy('registered_at'),
            default => $counting->sortBy(fn (Participation $p) => $p->person->birth_date->timestamp ?? PHP_INT_MAX),
        };

        $result = [];
        $position = 0;
        $count = $ordered->count();
        foreach ($ordered->values() as $p) {
            $position++;
            /** @var EventRole|null $role */
            $role = $roles[$p->role_key] ?? null;
            if ($role !== null && $role->role_discount_cents > 0) {
                $result[$p->id] = ['position' => $position, 'discount' => $role->role_discount_cents, 'kind' => 'role'];

                continue;
            }
            $discount = match ($event->sibling_mode) {
                'staggered' => $event->sibling_amount_cents * (min($position, $event->sibling_cap_position ?? PHP_INT_MAX) - 1),
                'all' => $count >= $event->sibling_min_children ? $event->sibling_amount_cents : 0,
                default => 0,
            };
            $result[$p->id] = ['position' => $position, 'discount' => max(0, $discount), 'kind' => $discount > 0 ? 'sibling' : null];
        }
        // Participants that do not count as siblings and have a role discount.
        foreach ($participations as $p) {
            if (! isset($result[$p->id])) {
                $role = $roles[$p->role_key] ?? null;
                $result[$p->id] = ['position' => 0, 'discount' => $role->role_discount_cents ?? 0, 'kind' => ($role->role_discount_cents ?? 0) > 0 ? 'role' : null];
            }
        }

        return $result;
    }
}
