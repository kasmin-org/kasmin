<?php

namespace App\Support\Auth;

use App\Models\ContactPoint;
use App\Models\Event;
use App\Models\Participation;
use App\Models\Person;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\Lists\EventLists;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

/**
 * Who may see and change what (RO01, RO04). Administrators may do everything; everyone else only what their roles allow.
 * API tokens, roles, mail accounts by default and the system settings stay with administrators.
 */
class Permissions
{
    public const AREAS = ['people', 'events', 'forms', 'finance', 'mailings', 'mail_settings', 'automations', 'settings', 'privacy', 'safety', 'audit'];

    /** Role => area => view or edit. Not listed = no access. */
    public const DEFAULTS = [
        'board' => ['people' => 'edit', 'events' => 'edit', 'forms' => 'edit', 'finance' => 'edit', 'mailings' => 'edit', 'automations' => 'edit', 'settings' => 'edit', 'privacy' => 'edit'],
        'treasurer' => ['people' => 'view', 'finance' => 'edit'],
        'safety_officer' => ['people' => 'view', 'events' => 'view', 'safety' => 'edit'],
        'camp_leader' => ['people' => 'view', 'events' => 'edit'],
        'counsellor' => ['events' => 'view'],
        'auditor' => ['finance' => 'view', 'audit' => 'view', 'privacy' => 'view'],
    ];

    /** @return array<string, string> */
    public static function ofRole(Role $role): array
    {
        if ($role->key === 'administrator') {
            return array_fill_keys(self::AREAS, 'edit');
        }
        $permissions = $role->permissions ?? self::DEFAULTS[$role->key] ?? [];

        return $role->is_admin ? array_filter(array_intersect_key($permissions, array_flip(self::AREAS)), fn ($level) => in_array($level, ['view', 'edit'], true)) : [];
    }

    public static function allows(User $user, string $area, string $level = 'view'): bool
    {
        if ($user->person === null) {
            return false;
        }
        foreach (Role::query()->whereIn('key', $user->person->activeRoleKeys())->get() as $role) {
            if ($role->ownEventsOnly()) {
                continue; // counts only where own events are asked for
            }
            $has = self::ofRole($role)[$area] ?? null;
            if ($has === 'edit' || ($has === 'view' && $level === 'view')) {
                return true;
            }
        }

        return false;
    }

    /** Days after the end in which an event still counts as an own event (RO01). */
    public const OWN_EVENT_DAYS = 180;

    /** How long after its end an event still counts as "own" (setting own_event_days, default 180). */
    public static function ownEventDays(): int
    {
        $days = Settings::get('own_event_days');

        return is_numeric($days) ? max(0, (int) $days) : self::OWN_EVENT_DAYS;
    }

    /**
     * Events a person may see or change through roles that only count for the own events: events the role was assigned
     * for, and events the person takes part in as team member. Somebody who only registered itself does not count until
     * the team confirms it.
     *
     * @return list<int>
     */
    public static function ownEvents(User $user, string $area = 'events', string $level = 'view', bool $admin = true): array
    {
        $person = $user->person;
        if ($person === null) {
            return [];
        }
        $today = now()->toDateString();
        $ids = collect();
        $byRole = false;
        foreach ($person->roleAssignments()->with('role')->where(fn ($q) => $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', $today))->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))->get() as $assignment) {
            $role = $assignment->role;
            if ($role === null || ! $role->ownEventsOnly() || ($admin && ! $role->is_admin)) {
                continue;
            }
            $has = ($admin ? self::ofRole($role) : ($role->permissions ?? self::DEFAULTS[$role->key] ?? []))[$area] ?? null;
            if ($has !== 'edit' && ! ($has === 'view' && $level === 'view')) {
                continue;
            }
            $assignment->event_id !== null ? $ids->push($assignment->event_id) : $byRole = true;
        }
        if ($byRole) {
            $ids = $ids->merge(Participation::query()->where('person_id', $person->id)->whereIn('role_key', ['staff', 'junior_counsellor'])->whereIn('status', EventLists::STATUSES)->get()
                ->reject(fn (Participation $p) => $p->isOwn() && $p->status === 'registered')->pluck('event_id'));
        }

        return $ids->isEmpty() ? [] : array_values(Event::query()->whereIn('id', $ids->unique())->where('ends_at', '>', now()->subDays(self::ownEventDays()))->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    /** All events, or the own ones. */
    public static function allowsEvent(User $user, int $eventId, string $level = 'view', string $area = 'events'): bool
    {
        return self::allows($user, $area, $level) || in_array($eventId, self::ownEvents($user, $area, $level), true);
    }

    public static function currentEvent(int $eventId, string $level = 'view', string $area = 'events'): bool
    {
        $user = Auth::user();

        return $user instanceof User && self::allowsEvent($user, $eventId, $level, $area);
    }

    /**
     * Own events of the logged-in user if it may not see all of them; null without restriction.
     *
     * @return list<int>|null
     */
    public static function restriction(string $area = 'events', string $level = 'view'): ?array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return [];
        }

        return self::allows($user, $area, $level) ? null : self::ownEvents($user, $area, $level);
    }

    /** The logged-in user in the admin interface. */
    public static function current(string $area, string $level = 'view'): bool
    {
        $user = Auth::user();

        return $user instanceof User && self::allows($user, $area, $level);
    }

    public static function administrator(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasAnyRole(['administrator']);
    }

    /**
     * The address of a person with an administrative role is the key to the admin interface: only administrators and the
     * person itself may change it, whatever page, import or function does it.
     */
    public static function guardContact(ContactPoint $point, bool $removing = false): void
    {
        $user = Auth::user();
        $unchanged = $point->exists && ! $removing && ! $point->isDirty();
        if (! $user instanceof User || $unchanged || $user->hasAnyRole(['administrator']) || ! in_array('email', [$point->getAttribute('type'), $point->getOriginal('type')], true)) {
            return; // commands and the scheduler have no user
        }
        foreach (array_unique([(int) $point->getAttribute('person_id'), (int) $point->getOriginal('person_id')]) as $id) {
            if ($id !== $user->person_id && self::administrative(Person::query()->find($id))) {
                throw new AuthorizationException(__('roles.contact_protected'));
            }
        }
    }

    /** Deleting a person with an administrative role would delete its login and roles. */
    public static function guardPerson(Person $person): void
    {
        $user = Auth::user();
        if ($user instanceof User && ! $user->hasAnyRole(['administrator']) && self::administrative($person)) {
            throw new AuthorizationException(__('roles.contact_protected'));
        }
        if (self::administrators() === [$person->id]) {
            throw new AuthorizationException(__('roles.last_administrator'));
        }
    }

    public static function guardAssignment(RoleAssignment $assignment, bool $removing): void
    {
        $user = Auth::user();
        if ($user instanceof User && ! $user->hasAnyRole(['administrator'])) {
            throw new AuthorizationException(__('roles.only_administrators'));
        }
        if ($assignment->exists && ($removing || $assignment->isDirty())) {
            self::keepOneAdministrator($assignment, $removing);
        }
    }

    public static function administrative(?Person $person): bool
    {
        return $person !== null && Role::query()->whereIn('key', $person->activeRoleKeys())->where('is_admin', true)->exists();
    }

    /** @return list<int> people who are administrators today and can log in */
    public static function administrators(?int $exceptAssignment = null): array
    {
        $today = now()->toDateString();

        return array_values(RoleAssignment::query()->whereHas('role', fn ($q) => $q->where('key', 'administrator'))
            ->whereHas('person', fn ($q) => $q->whereNull('anonymised_at')->whereHas('user'))
            ->when($exceptAssignment !== null, fn ($q) => $q->whereKeyNot($exceptAssignment))
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', $today))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
            ->pluck('person_id')->unique()->map(fn ($id) => (int) $id)->all());
    }

    private static function keepOneAdministrator(RoleAssignment $assignment, bool $removing): void
    {
        $role = Role::query()->find((int) $assignment->getOriginal('role_id'));
        if ($role?->key !== 'administrator' || self::administrators($assignment->id) !== []) {
            return;
        }
        $ends = $assignment->getAttribute('ends_on');
        $starts = $assignment->getAttribute('starts_on');
        $stays = ! $removing && (int) $assignment->getAttribute('role_id') === $role->id && (int) $assignment->getAttribute('person_id') === (int) $assignment->getOriginal('person_id')
            && ($ends === null || $ends->gte(now()->startOfDay())) && ($starts === null || $starts->lte(now()));
        if (! $stays) {
            throw new AuthorizationException(__('roles.last_administrator'));
        }
    }
}
