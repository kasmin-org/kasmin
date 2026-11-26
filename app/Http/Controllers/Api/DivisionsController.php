<?php

namespace App\Http\Controllers\Api;

use App\Models\EventDivision;
use App\Models\EventRole;
use App\Models\EventUnit;
use App\Models\Participation;
use App\Support\Audit\Audit;
use App\Support\Events\Divisions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Groups, rooms and duties within an event: divisions with units, the proposal that spreads the people, and moving
 * people by hand. Scopes: events:read, events:write.
 */
class DivisionsController extends ApiController
{
    public function __construct(private readonly Divisions $divisions) {}

    /** The divisions of an event with their units, the people in each unit, the people without a unit and what to check by hand. */
    public function index(string $event): JsonResponse
    {
        $this->scope('events:read');
        $model = $this->event($event);
        $divisions = EventDivision::query()->where('event_id', $model->id)->orderBy('sort')->with('units', 'event')->get();

        return response()->json(['data' => $divisions->map(fn (EventDivision $d) => $this->present($d))->values()]);
    }

    /**
     * Creates a division with a number of units. kind: groups, rooms or duty. options (all optional, the defaults
     * depend on the kind): roles, balance_age, balance_gender, separate_gender, siblings (apart, together, any).
     * Nobody is placed yet; use the proposal.
     */
    public function store(Request $request, string $event): JsonResponse
    {
        $this->scope('events:write');
        $model = $this->event($event);
        $data = $request->validate([
            'kind' => ['required', Rule::in(EventDivision::KINDS)], 'name' => ['required', 'string', 'max:120'],
            'units' => ['required', 'integer', 'between:1,100'], 'capacity' => ['sometimes', 'nullable', 'integer', 'between:1,500'],
            'options' => ['sometimes', 'array:roles,balance_age,balance_gender,separate_gender,siblings'],
            'options.roles' => ['sometimes', 'array', 'min:1'], 'options.roles.*' => ['string', 'distinct', Rule::in(EventRole::KEYS)],
            'options.balance_age' => ['sometimes', 'boolean'], 'options.balance_gender' => ['sometimes', 'boolean'], 'options.separate_gender' => ['sometimes', 'boolean'],
            'options.siblings' => ['sometimes', Rule::in(['apart', 'together', 'any'])],
        ]);
        $given = ($data['options'] ?? []) + EventDivision::DEFAULTS[$data['kind']];
        // As in the form: genders cannot be mixed evenly and separated at the same time.
        $options = ['balance_age' => (bool) $given['balance_age'], 'balance_gender' => (bool) $given['balance_gender'] && ! $given['separate_gender'],
            'separate_gender' => (bool) $given['separate_gender'], 'siblings' => (string) $given['siblings'], 'roles' => array_values((array) $given['roles'])];
        $division = $this->divisions->create($model, $data['name'], $data['kind'], (int) $data['units'], isset($data['capacity']) ? (int) $data['capacity'] : null, $options);
        Audit::record('event.division_created', $division);

        return response()->json(['data' => $this->present($division->load('units', 'event'))], 201);
    }

    /** Deletes a division with its units; the people stay registered. */
    public function destroy(int $division): JsonResponse
    {
        $this->scope('events:write');
        $model = EventDivision::query()->findOrFail($division);
        Audit::record('event.division_deleted', $model);
        $model->delete();

        return response()->json(['data' => null]);
    }

    /** Places everybody who has no unit yet; with restart, everybody is taken out first and placed again. */
    public function propose(Request $request, int $division): JsonResponse
    {
        $this->scope('events:write');
        $model = EventDivision::query()->with('units', 'event')->findOrFail($division);
        $restart = (bool) ($request->validate(['restart' => ['sometimes', 'boolean']])['restart'] ?? false);
        $count = $this->divisions->propose($model, $restart);
        Audit::record('event.division_proposed', $model, meta: ['placed' => $count, 'restart' => $restart]);

        return response()->json(['data' => $this->present($model), 'placed' => $count]);
    }

    /** Puts one person (the number of the participation) into a unit of the division; unit null takes it out. */
    public function move(Request $request, int $division, int $participation): JsonResponse
    {
        $this->scope('events:write');
        $model = EventDivision::query()->with('units', 'event')->findOrFail($division);
        $data = $request->validate(['unit' => ['present', 'nullable', 'integer']]);
        $person = Participation::query()->where('event_id', $model->event_id)->findOrFail($participation);
        $unit = $data['unit'] !== null ? EventUnit::query()->where('event_division_id', $model->id)->find((int) $data['unit']) : null;
        abort_if($data['unit'] !== null && $unit === null, 422, 'The unit does not belong to this division.');
        $this->divisions->move($model, $person, $unit);

        return response()->json(['data' => $this->present($model)]);
    }

    /** One more unit at the end of the division. */
    public function addUnit(int $division): JsonResponse
    {
        $this->scope('events:write');
        $model = EventDivision::query()->with('event')->findOrFail($division);
        $this->divisions->addUnit($model);

        return response()->json(['data' => $this->present($model->load('units'))], 201);
    }

    /** Changes the name, the note or the size of a unit (capacity null = no limit). */
    public function updateUnit(Request $request, int $unit): JsonResponse
    {
        $this->scope('events:write');
        $model = EventUnit::query()->with('division.event')->findOrFail($unit);
        $model->update($request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:120'], 'note' => ['sometimes', 'nullable', 'string', 'max:250'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'between:1,500'],
        ]));

        return response()->json(['data' => $this->present(($model->division ?? abort(404))->load('units'))]);
    }

    /** Removes a unit that has nobody in it. */
    public function destroyUnit(int $unit): JsonResponse
    {
        $this->scope('events:write');
        $model = EventUnit::query()->with('division.event')->findOrFail($unit);
        abort_if(DB::table('event_unit_members')->where('event_unit_id', $model->id)->exists(), 422, 'Only a unit without people can be removed.');
        $division = $model->division ?? abort(404);
        $model->delete();

        return response()->json(['data' => $this->present($division->load('units'))]);
    }

    /** @return array<string, mixed> */
    private function present(EventDivision $division): array
    {
        $event = $division->event ?? abort(404);
        $people = $this->divisions->people($division);
        $placed = $this->divisions->placed($division);
        $row = fn (Participation $p) => [
            'participation' => $p->id, 'person' => $p->person?->public_id, 'name' => $p->person?->fullName(), 'age' => $p->person?->ageAt($event->starts_at),
            'gender' => $p->person?->getAttribute('gender'), 'role' => $p->role_key,
        ];

        return [
            'id' => $division->id, 'event' => $event->public_id, 'name' => $division->name, 'kind' => $division->kind,
            'options' => array_map(fn (string $key) => $division->option($key), array_combine(array_keys(EventDivision::DEFAULTS[$division->kind]), array_keys(EventDivision::DEFAULTS[$division->kind]))),
            'units' => $division->units->map(fn (EventUnit $u) => [
                'id' => $u->id, 'name' => $u->name, 'capacity' => $u->capacity, 'note' => $u->note,
                'people' => $people->filter(fn (Participation $p) => ($placed[$p->id] ?? null) === $u->id)->map($row)->values()->all(),
            ])->values()->all(),
            'not_placed' => $people->reject(fn (Participation $p) => isset($placed[$p->id]))->map($row)->values()->all(),
            'warnings' => $this->divisions->warnings($division),
        ];
    }
}
