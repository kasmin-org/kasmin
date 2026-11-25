<?php

namespace App\Http\Controllers\Api;

use App\Models\ContactPoint;
use App\Models\Person;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * E-mail addresses and phone numbers of a person. Scopes: people:read, people:write.
 */
class ContactPointsController extends ApiController
{
    /** E-mail addresses and phone numbers of a person, the primary ones first. */
    public function index(string $person): JsonResponse
    {
        $this->scope('people:read');

        return $this->list($this->person($person));
    }

    /**
     * Adds an e-mail address or a phone number. The first one of its type becomes the primary one; with is_primary
     * it replaces the primary one. A new address does not change the login of the person.
     */
    public function store(Request $request, string $person): JsonResponse
    {
        $this->scope('people:write');
        $model = $this->person($person);
        $data = $request->validate([
            'type' => ['required', Rule::in(['email', 'phone'])],
            'value' => ['required', 'string', 'max:254'],
            'purposes' => ['nullable', 'array'],
            'purposes.*' => ['string', 'distinct', Rule::in(ContactPoint::PURPOSES)],
            'is_primary' => ['nullable', 'boolean'],
        ]);
        $value = trim($data['value']);
        $request->merge(['value' => $value])->validate(['value' => $data['type'] === 'email' ? ['email'] : ['max:40', 'regex:/^[0-9+()\/ .-]+$/']]);
        $value = $data['type'] === 'email' ? mb_strtolower($value) : $value;
        $same = $model->contactPoints()->where('type', $data['type']);
        if ((clone $same)->where('value', $value)->exists()) {
            throw ValidationException::withMessages(['value' => ['The person already has this '.($data['type'] === 'email' ? 'address' : 'number').'.']]);
        }
        $primary = (bool) ($data['is_primary'] ?? false) || ! (clone $same)->exists();
        if ($primary) {
            foreach ((clone $same)->where('is_primary', true)->get() as $other) {
                $other->update(['is_primary' => false]);
            }
        }
        $model->contactPoints()->create(['type' => $data['type'], 'value' => $value, 'purposes' => array_values($data['purposes'] ?? []) ?: null, 'is_primary' => $primary]);

        return $this->list($model, 201);
    }

    /** Removes an address or number. The address of the login of the person cannot be removed. */
    public function destroy(string $person, int $point): JsonResponse
    {
        $this->scope('people:write');
        $model = $this->person($person);
        /** @var ContactPoint $contact */
        $contact = $model->contactPoints()->findOrFail($point);
        $login = $model->user?->email;
        abort_if($contact->type === 'email' && $login !== null && mb_strtolower($login) === $contact->value, 422, 'This address is needed for the login of the person.');
        $contact->delete();

        return $this->list($model);
    }

    private function list(Person $person, int $status = 200): JsonResponse
    {
        $points = $person->contactPoints()->orderBy('type')->orderByDesc('is_primary')->orderBy('id')->get();

        return response()->json(['data' => $points->map(fn (ContactPoint $c) => [
            'id' => (int) $c->getKey(), 'type' => $c->type, 'value' => $c->value, 'purposes' => $c->purposes ?? [], 'primary' => (bool) $c->getAttribute('is_primary'),
            'verified_at' => $c->getAttribute('verified_at')?->toIso8601String(), 'bounced_at' => $c->bounced_at?->toIso8601String(),
        ])->values()], $status);
    }
}
