<?php

namespace App\Http\Controllers\Api;

use App\Models\Person;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Support\Api\ApiContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Roles and who has them. Scopes: people:read to read, roles:write to give and take roles. A token can never give
 * or take the role administrator: that stays with a person in the admin interface. Via MCP, giving and taking roles
 * also needs approvals:decide: a role can make somebody an approver, and an AI assistant must not do that on its
 * own (X05, MI12).
 */
class RolesController extends ApiController
{
    /** All roles; admin = the role may use the admin interface, own_events_only = it counts for the own events only. */
    public function index(): JsonResponse
    {
        $this->scope('people:read');

        return response()->json(['data' => Role::query()->orderBy('id')->get()->map(fn (Role $r) => [
            'key' => $r->key, 'name' => $r->name, 'admin' => $r->is_admin, 'own_events_only' => $r->ownEventsOnly(), 'assignable' => $r->key !== 'administrator',
        ])->values()]);
    }

    /** The roles of a person with their period, also the ended ones. */
    public function ofPerson(string $person): JsonResponse
    {
        $this->scope('people:read');

        return $this->list($this->person($person));
    }

    /**
     * Gives a person a role: role is the key; event (id of an event) only for roles that count for the own events;
     * starts_on defaults to today. A person who gets a role for the admin interface also gets a login for its
     * primary e-mail address. The role administrator is refused (403).
     */
    public function store(Request $request, string $person): JsonResponse
    {
        $this->scope('roles:write');
        $this->protect();
        $model = $this->person($person);
        $data = $request->validate([
            'role' => ['required', 'string', 'exists:roles,key'], 'event' => ['sometimes', 'nullable', 'string'],
            'starts_on' => ['sometimes', 'nullable', 'date'], 'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:'.($request->input('starts_on') ? 'starts_on' : 'today')],
        ]);
        $role = Role::query()->where('key', $data['role'])->firstOrFail();
        $this->notAdministrator($role);
        $event = isset($data['event']) ? $this->event($data['event']) : null;
        if ($event !== null && ! $role->ownEventsOnly()) {
            throw ValidationException::withMessages(['event' => ['This role is not limited to events.']]);
        }
        $model->roleAssignments()->create([
            'role_id' => $role->id, 'event_id' => $event?->id,
            'starts_on' => array_key_exists('starts_on', $data) ? $data['starts_on'] : now()->toDateString(), 'ends_on' => $data['ends_on'] ?? null,
        ]);

        return $this->list($model, 201);
    }

    /** Takes a role from a person (the number of the assignment). The role administrator is refused (403). */
    public function destroy(string $person, int $assignment): JsonResponse
    {
        $this->scope('roles:write');
        $this->protect();
        $model = $this->person($person);
        /** @var RoleAssignment $item */
        $item = $model->roleAssignments()->with('role')->findOrFail($assignment);
        if ($item->role !== null) {
            $this->notAdministrator($item->role);
        }
        $item->delete();

        return $this->list($model);
    }

    /**
     * Roles decide who may approve (approver roles of automations) and who may use the admin interface, so via
     * MCP they are protected like the approval settings of an automation (AutomationInput::protect).
     *
     * @throws AuthorizationException
     */
    private function protect(): void
    {
        if (ApiContext::viaMcp() && ! ApiContext::allows('approvals:decide')) {
            throw new AuthorizationException('Via MCP, giving and taking roles needs the scope approvals:decide.');
        }
    }

    private function notAdministrator(Role $role): void
    {
        abort_if($role->key === 'administrator', 403, 'The role administrator can only be given and taken in the admin interface.');
    }

    private function list(Person $person, int $status = 200): JsonResponse
    {
        $items = $person->roleAssignments()->with('role', 'event')->orderBy('id')->get();

        return response()->json(['data' => $items->map(fn (RoleAssignment $a) => [
            'id' => (int) $a->getKey(), 'role' => $a->role?->key, 'name' => $a->role?->label(), 'event' => $a->event?->public_id,
            'starts_on' => $a->getAttribute('starts_on')?->toDateString(), 'ends_on' => $a->getAttribute('ends_on')?->toDateString(),
        ])->values()], $status);
    }
}
