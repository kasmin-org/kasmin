<?php

namespace App\Http\Controllers\Api;

use App\Models\Membership;
use App\Models\MembershipType;
use App\Models\Person;
use App\Support\Records\Records;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Memberships of a person: which membership type from when to when. Scopes: people:read, people:write.
 */
class MembershipsController extends ApiController
{
    /** The memberships of a person, the newest first. */
    public function index(string $person): JsonResponse
    {
        $this->scope('people:read');

        return $this->list($this->person($person));
    }

    /**
     * Gives the person a membership: type is the id or the name of a membership type, starts_on defaults to today. A person
     * has one running membership per type at most.
     */
    public function store(Request $request, string $person): JsonResponse
    {
        $this->scope('people:write');
        $model = $this->person($person);
        $data = $request->validate([
            'type' => ['required'], 'starts_on' => ['sometimes', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:'.($request->input('starts_on') ? 'starts_on' : 'today')],
        ]);
        $type = Records::require(MembershipType::query(), $data['type'], 'type');
        if ($model->memberships()->where('membership_type_id', $type->id)->whereNull('ends_on')->exists()) {
            throw ValidationException::withMessages(['type' => ['The person already has a running membership of this type.']]);
        }
        $model->memberships()->create(['membership_type_id' => $type->id, 'starts_on' => $data['starts_on'] ?? now()->toDateString(), 'ends_on' => $data['ends_on'] ?? null]);

        return $this->list($model, 201);
    }

    /** Ends a membership (ends_on), or lets it run again (ends_on null). */
    public function update(Request $request, string $person, int $membership): JsonResponse
    {
        $this->scope('people:write');
        $model = $this->person($person);
        /** @var Membership $item */
        $item = $model->memberships()->findOrFail($membership);
        $data = $request->validate(['ends_on' => ['present', 'nullable', 'date', 'after_or_equal:'.$item->getAttribute('starts_on')->toDateString()]]);
        if ($data['ends_on'] === null && $model->memberships()->where('membership_type_id', $item->getAttribute('membership_type_id'))->whereNull('ends_on')->whereKeyNot($item->getKey())->exists()) {
            throw ValidationException::withMessages(['ends_on' => ['The person already has a running membership of this type.']]);
        }
        $item->update(['ends_on' => $data['ends_on']]);

        return $this->list($model);
    }

    private function list(Person $person, int $status = 200): JsonResponse
    {
        $items = $person->memberships()->with('type')->orderByDesc('starts_on')->orderByDesc('id')->get();

        return response()->json(['data' => $items->map(fn (Membership $m) => [
            'id' => (int) $m->getKey(), 'type' => $m->getAttribute('membership_type_id'), 'name' => $m->type?->label(),
            'starts_on' => $m->getAttribute('starts_on')?->toDateString(), 'ends_on' => $m->getAttribute('ends_on')?->toDateString(),
        ])->values()], $status);
    }
}
