<?php

namespace App\Http\Controllers\Api;

use App\Models\Household;
use App\Support\Api\Presenter;
use App\Support\Audit\Audit;
use App\Support\People\Families;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Families: create, change, members and invoice recipient. Scope: people:write (reading: GET /households).
 */
class HouseholdsController extends ApiController
{
    /** Creates a family without members; add them with POST /households/{household}/members/{person}. */
    public function store(Request $request): JsonResponse
    {
        $this->scope('people:write');
        $household = Household::query()->create($this->data($request, null));

        return response()->json(['data' => Presenter::household($household->refresh()->load('members', 'invoiceRecipient'))], 201);
    }

    /**
     * Changes name, address and language. invoice_recipient is the id of one of the legal guardians of the family,
     * or null for the automatic choice.
     */
    public function update(Request $request, string $household): JsonResponse
    {
        $this->scope('people:write');
        $model = $this->household($household);
        $model->update($this->data($request, $model));

        return response()->json(['data' => Presenter::household($model->refresh()->load('members', 'invoiceRecipient'))]);
    }

    /**
     * Adds a person to the family as legal guardian or child (role guardian or child), or changes its role. As in
     * the admin interface, the legal guardians of the family become the legal guardians of its children; a
     * guardianship that was ended stays ended.
     */
    public function addMember(Request $request, string $household, string $person): JsonResponse
    {
        $this->scope('people:write');
        $model = $this->household($household);
        $member = $this->person($person);
        $role = (string) $request->validate(['role' => ['required', Rule::in(['guardian', 'child'])]])['role'];
        $current = $model->members()->whereKey($member->id)->first()?->getRelationValue('pivot')?->getAttribute('role');
        if ($current === null) {
            $model->members()->attach($member->id, ['role' => $role]);
        } elseif ($current !== $role) {
            $model->members()->updateExistingPivot($member->id, ['role' => $role]);
            if ($model->getAttribute('invoice_recipient_id') === $member->id) {
                $model->update(['invoice_recipient_id' => null]); // only a legal guardian gets the invoices
            }
        }
        if ($current !== $role) {
            app(Families::class)->linkGuardians($model);
            Audit::record('household.member_added', $model, null, ['person_id' => $member->id, 'role' => $role]);
        }

        return response()->json(['data' => Presenter::household($model->refresh()->load('members', 'invoiceRecipient'))], $current === null ? 201 : 200);
    }

    /** Takes a person out of the family. The person and its guardianships stay. */
    public function removeMember(string $household, string $person): JsonResponse
    {
        $this->scope('people:write');
        $model = $this->household($household);
        $member = $model->members()->where('people.public_id', $person)->firstOrFail();
        $model->members()->detach($member->id);
        if ($model->getAttribute('invoice_recipient_id') === $member->id) {
            $model->update(['invoice_recipient_id' => null]);
        }
        Audit::record('household.member_removed', $model, null, ['person_id' => $member->id]);

        return response()->json(['data' => Presenter::household($model->refresh()->load('members', 'invoiceRecipient'))]);
    }

    private function household(string $id): Household
    {
        return Household::query()->where('public_id', $id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function data(Request $request, ?Household $household): array
    {
        $data = $request->validate([
            'name' => [$household === null ? 'required' : 'sometimes', 'string', 'max:200'],
            'locale' => ['sometimes', Rule::in(['de', 'en'])],
            'street' => ['sometimes', 'nullable', 'string', 'max:200'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9 -]+$/'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'country' => ['sometimes', 'string', 'size:2', 'alpha'],
            'invoice_recipient' => $household === null ? ['prohibited'] : ['sometimes', 'nullable', 'string'],
        ]);
        if (isset($data['country'])) {
            $data['country'] = mb_strtoupper((string) $data['country']);
        }
        if (array_key_exists('invoice_recipient', $data)) {
            $recipient = $data['invoice_recipient'] !== null ? $household?->guardians()->where('people.public_id', $data['invoice_recipient'])->first() : null;
            if ($data['invoice_recipient'] !== null && $recipient === null) {
                throw ValidationException::withMessages(['invoice_recipient' => ['The invoice recipient must be a legal guardian of this family.']]);
            }
            $data['invoice_recipient_id'] = $recipient?->id;
            unset($data['invoice_recipient']);
        }

        return $data;
    }
}
