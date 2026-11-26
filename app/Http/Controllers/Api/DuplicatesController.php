<?php

namespace App\Http\Controllers\Api;

use App\Support\Api\Presenter;
use App\Support\People\Duplicates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * People that are probably the same: list, merge, or mark as different. Scopes: people:read, people:write. Nothing
 * is ever merged automatically.
 */
class DuplicatesController extends ApiController
{
    /** Pairs of people that are probably the same, with the reason (name, name_birth, sound_birth, mail). At most 100. */
    public function index(): JsonResponse
    {
        $this->scope('people:read');
        $pairs = array_map(fn (array $pair) => ['first' => Presenter::person($pair['first']), 'second' => Presenter::person($pair['second']), 'reason' => $pair['reason']], app(Duplicates::class)->all());

        return response()->json(['data' => $pairs]);
    }

    /**
     * Moves everything from the person "duplicate" to the person "keep" and deletes the duplicate. This cannot be
     * undone. fields says per field (first_name, last_name, birth_date, gender) whose value stays: "keep"
     * (default) or "duplicate". Refused (422) when the merge would give somebody more rights or both people have
     * an invoiced registration for the same event.
     */
    public function merge(Request $request): JsonResponse
    {
        $this->scope('people:write');
        $data = $request->validate([
            'keep' => ['required', 'string'], 'duplicate' => ['required', 'string', 'different:keep'],
            'fields' => ['sometimes', 'array:'.implode(',', Duplicates::FIELDS)], 'fields.*' => [Rule::in(['keep', 'duplicate'])],
        ]);
        $keep = $this->person($data['keep']);
        $duplicate = $this->person($data['duplicate']);
        $fields = [];
        foreach ($data['fields'] ?? [] as $field => $choice) {
            $fields[$field] = ($choice === 'duplicate' ? $duplicate : $keep)->getAttribute($field);
        }
        try {
            app(Duplicates::class)->merge($keep, $duplicate, $fields);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => Presenter::person($keep->refresh()->load('contactPoints', 'guardians', 'wards', 'households', 'groups', 'participations.event'), true)]);
    }

    /** Marks two people as different; the pair is not proposed again. */
    public function dismiss(Request $request): JsonResponse
    {
        $this->scope('people:write');
        $data = $request->validate(['first' => ['required', 'string'], 'second' => ['required', 'string', 'different:first']]);
        app(Duplicates::class)->dismiss($this->person($data['first']), $this->person($data['second']));

        return response()->json(['data' => null]);
    }
}
