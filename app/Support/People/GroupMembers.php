<?php

namespace App\Support\People;

use App\Models\Event;
use App\Models\Group;
use App\Models\Participation;
use App\Models\Person;
use App\Support\Audit\Audit;
use App\Support\Automations\Triggers;
use App\Support\Lists\EventLists;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Members of groups (GR02–GR04). Dynamic and event groups are recalculated; memberships are ended, never deleted, so the
 * history shows who was in a group, when and why.
 */
class GroupMembers
{
    public const FIELDS = ['age', 'role', 'membership', 'group', 'events'];

    /** Recalculates all dynamic and event groups; returns the number of changes. */
    public function updateAll(): int
    {
        return Group::query()->whereIn('kind', ['dynamic', 'event'])->get()->sum(fn (Group $g) => $this->update($g));
    }

    public function update(Group $group): int
    {
        if (! in_array($group->kind, ['dynamic', 'event'], true)) {
            return 0;
        }
        $should = $this->matching($group);

        return DB::transaction(function () use ($group, $should) {
            $has = $group->people()->pluck('people.id')->map(fn ($id) => (int) $id);
            $joined = $should->diff($has);
            $left = $has->diff($should);
            foreach ($joined as $id) {
                self::join($group, (int) $id, $group->kind);
            }
            foreach ($left as $id) {
                self::leave($group, (int) $id);
            }
            $group->forceFill(['updated_members_at' => now()])->saveQuietly();
            if ($joined->isNotEmpty() || $left->isNotEmpty()) {
                Audit::record('group.members_updated', $group, null, ['joined' => $joined->count(), 'left' => $left->count()], 'system');
            }

            return $joined->count() + $left->count();
        });
    }

    /** @return Collection<int, int> ids of the people who belong to the group now */
    public function matching(Group $group): Collection
    {
        $filter = $group->filter ?? [];
        if ($group->kind === 'event') {
            $statuses = $filter['statuses'] ?? EventLists::STATUSES;

            return $group->event_id === null ? collect() : Participation::query()->where('event_id', $group->event_id)->whereIn('status', $statuses)
                ->when(! empty($filter['roles']), fn ($q) => $q->whereIn('role_key', $filter['roles']))
                ->whereHas('person', fn ($q) => $q->whereNull('anonymised_at'))->pluck('person_id')->unique()->map(fn ($id) => (int) $id)->values();
        }
        $rules = array_values(array_filter((array) ($filter['rules'] ?? []), fn ($r) => is_array($r) && in_array($r['field'] ?? null, self::FIELDS, true)));
        if ($rules === []) {
            return collect(); // an empty filter must not mean everybody
        }
        $any = ($filter['match'] ?? 'all') === 'any';
        $event = $group->event_id !== null ? Event::query()->find($group->event_id) : null;

        return Person::query()->whereNull('anonymised_at')->with(['memberships', 'participations.event', 'groups', 'roleAssignments.role'])->get()
            ->filter(function (Person $person) use ($rules, $any, $event, $group) {
                foreach ($rules as $rule) {
                    $fits = $this->fits($person, $rule, $event, $group);
                    if ($any === $fits) {
                        return $any;
                    }
                }

                return ! $any;
            })->map(fn (Person $p) => $p->id)->values();
    }

    /** @param  array<string, mixed>  $rule */
    private function fits(Person $person, array $rule, ?Event $event, Group $group): bool
    {
        $value = $rule['value'] ?? null;
        $fits = match ($rule['field']) {
            'age' => $this->between($person->ageAt($event?->ageReferenceDate()), $rule),
            'membership' => $person->memberships->contains(fn ($m) => (int) $m->membership_type_id === (int) $value && ($m->ends_on === null || Carbon::parse($m->ends_on)->gte(now()->startOfDay()))),
            'role' => $person->roleAssignments->contains(fn ($a) => (int) $a->role_id === (int) $value && ($a->ends_on === null || Carbon::parse($a->ends_on)->gte(now()->startOfDay()))),
            'group' => (int) $value !== $group->id && $person->groups->contains('id', (int) $value),
            'events' => $this->between($person->participations->filter(fn (Participation $p) => in_array($p->status, EventLists::STATUSES, true) && $p->event !== null && $p->event->ends_at->isPast()
                && (blank($value) || $p->role_key === $value)
                && ($event?->event_series_id === null || $p->event->event_series_id === $event->event_series_id))->unique('event_id')->count(), $rule),
            default => false,
        };

        return ($rule['not'] ?? false) ? ! $fits : $fits;
    }

    /** @param  array<string, mixed>  $rule */
    private function between(?int $number, array $rule): bool
    {
        return $number !== null && (blank($rule['min'] ?? null) || $number >= (int) $rule['min']) && (blank($rule['max'] ?? null) || $number <= (int) $rule['max']);
    }

    /** @return Collection<int, int> members including those of subgroups (GR02) */
    public function withSubgroups(Group $group): Collection
    {
        $ids = [$group->id];
        for ($next = $ids; $next !== [];) {
            $next = Group::query()->whereIn('parent_id', $next)->whereNotIn('id', $ids)->pluck('id')->all();
            $ids = [...$ids, ...$next];
        }

        return Person::query()->whereHas('groups', fn ($q) => $q->whereIn('groups.id', $ids))->pluck('id')->map(fn ($id) => (int) $id);
    }

    public static function join(Group $group, int $personId, string $source): void
    {
        if (! $group->people()->whereKey($personId)->exists()) {
            DB::table('group_memberships')->insert(['group_id' => $group->id, 'person_id' => $personId, 'starts_on' => now()->toDateString(), 'source' => $source, 'created_at' => now(), 'updated_at' => now()]);
            Triggers::group('on_group_joined', $group, $personId);
        }
    }

    public static function leave(Group $group, int $personId): void
    {
        $ended = DB::table('group_memberships')->where('group_id', $group->id)->where('person_id', $personId)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', now()->toDateString()))
            ->update(['ends_on' => now()->subDay()->toDateString(), 'updated_at' => now()]);
        if ($ended > 0) {
            Triggers::group('on_group_left', $group, $personId);
        }
    }
}
