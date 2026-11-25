<?php

namespace App\Http\Controllers\Api;

use App\Models\Event;
use App\Models\Group;
use App\Models\Person;
use App\Support\People\GroupMembers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Groups and their members. Scopes: people:read, people:write.
 */
class GroupsController extends ApiController
{
    /** All groups with the number of members. */
    public function index(): JsonResponse
    {
        $this->scope('people:read');

        return response()->json(['data' => Group::query()->withCount('people')->with('event')->orderBy('name')->get()->map(fn (Group $g) => $this->present($g))->values()]);
    }

    /** One group with its members. */
    public function show(int $group): JsonResponse
    {
        $this->scope('people:read');
        $model = Group::query()->withCount('people')->with('event')->findOrFail($group);
        $members = $model->people()->orderBy('last_name')->get()->map(fn (Person $p) => ['id' => $p->public_id, 'name' => $p->fullName()])->values();

        return response()->json(['data' => $this->present($model) + ['members' => $members]]);
    }

    /** Creates a group: manual, dynamic (with rules) or event (with an event). */
    public function store(Request $request): JsonResponse
    {
        $this->scope('people:write');
        $group = Group::query()->create($this->data($request, true));
        app(GroupMembers::class)->update($group);

        return response()->json(['data' => $this->present($group->loadCount('people')->load('event'))], 201);
    }

    /** Changes a group. */
    public function update(Request $request, int $group): JsonResponse
    {
        $this->scope('people:write');
        $model = Group::query()->findOrFail($group);
        $model->update($this->data($request, false));
        app(GroupMembers::class)->update($model);

        return response()->json(['data' => $this->present($model->loadCount('people')->load('event'))]);
    }

    /** Adds a person to a manual group: 201 when the person was added, 200 when it already was a member. */
    public function addMember(Request $request, int $group): JsonResponse
    {
        $this->scope('people:write');
        $model = $this->manual($group);
        $added = GroupMembers::join($model, $this->person((string) $request->validate(['person' => ['required', 'string']])['person'])->id, 'api');

        return response()->json(['data' => $this->present($model->loadCount('people'))], $added ? 201 : 200);
    }

    /** Ends the membership of a person in a manual group. */
    public function removeMember(int $group, string $person): JsonResponse
    {
        $this->scope('people:write');
        $model = $this->manual($group);
        GroupMembers::leave($model, $this->person($person)->id);

        return response()->json(['data' => $this->present($model->loadCount('people'))]);
    }

    private function manual(int $id): Group
    {
        $group = Group::query()->findOrFail($id);
        abort_if($group->kind !== 'manual', 422, 'The members of this group come from its rules.');

        return $group;
    }

    /** @return array<string, mixed> */
    private function data(Request $request, bool $new): array
    {
        $need = $new ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$need, 'string', 'max:150'],
            'kind' => [$need, Rule::in(['manual', 'dynamic', 'event'])],
            'description' => ['nullable', 'string', 'max:2000'],
            'event' => ['nullable', 'string'],
            'parent' => ['nullable', 'integer', 'exists:groups,id'],
            'filter' => ['nullable', 'array'],
            'filter.match' => ['nullable', Rule::in(['all', 'any'])],
            'filter.rules' => ['nullable', 'array', 'max:20'],
            'filter.rules.*.field' => ['required', Rule::in(GroupMembers::FIELDS)],
            'filter.rules.*.value' => ['nullable'],
            'filter.rules.*.min' => ['nullable', 'integer'],
            'filter.rules.*.max' => ['nullable', 'integer'],
            'filter.rules.*.not' => ['nullable', 'boolean'],
            'filter.statuses' => ['nullable', 'array'],
            'filter.statuses.*' => ['string', 'max:20'],
            'filter.roles' => ['nullable', 'array'],
            'filter.roles.*' => ['string', 'max:32'],
        ]);
        if (array_key_exists('event', $data)) {
            $data['event_id'] = $data['event'] !== null ? Event::query()->where('public_id', $data['event'])->firstOrFail()->id : null;
        }
        if (array_key_exists('parent', $data)) {
            $data['parent_id'] = $data['parent'];
        }

        return array_diff_key($data, array_flip(['event', 'parent']));
    }

    /** @return array<string, mixed> */
    private function present(Group $g): array
    {
        return ['id' => $g->id, 'name' => $g->name, 'kind' => $g->kind, 'parent' => $g->getAttribute('parent_id'), 'event' => $g->event?->public_id,
            'filter' => $g->filter, 'description' => $g->getAttribute('description'), 'members_count' => (int) $g->getAttribute('people_count')];
    }
}
