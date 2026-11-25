<?php

namespace App\Http\Controllers\Api;

use App\Models\Household;
use App\Models\Person;
use App\Support\Api\Presenter;
use App\Support\Import\PeopleImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * People and families. Scopes: people:read, people:write.
 */
class PeopleController extends ApiController
{
    /** List people; filter by search text, group id or changes since a date. */
    public function index(Request $request): JsonResponse
    {
        $this->scope('people:read');
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'group' => ['nullable', 'integer'], 'updated_since' => ['nullable', 'date'],
        ]);
        $page = Person::query()->whereNull('anonymised_at')
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('first_name', 'like', "%{$s}%")->orWhere('last_name', 'like', "%{$s}%")))
            ->when($data['group'] ?? null, fn ($q, $g) => $q->whereHas('groups', fn ($q) => $q->where('groups.id', $g)))
            ->when($data['updated_since'] ?? null, fn ($q, $d) => $q->where('updated_at', '>=', $d))
            ->orderBy('last_name')->orderBy('first_name')->paginate($this->perPage());

        return response()->json(['data' => $page->getCollection()->map(fn (Person $p) => Presenter::person($p))->values(), 'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    /** One person with contacts, legal guardians, children, families, groups and participations (no health data). */
    public function show(string $person): JsonResponse
    {
        $this->scope('people:read');
        $model = $this->person($person)->load('contactPoints', 'guardians', 'wards', 'households', 'groups', 'participations.event');

        return response()->json(['data' => Presenter::person($model, true)]);
    }

    /**
     * Create a person (with legal guardians, family, groups) – the same fields as one import row. Recorded as an
     * import batch with source "api", so it can be rolled back.
     */
    public function store(Request $request): JsonResponse
    {
        $this->scope('people:write');
        $row = $request->validate([
            'first_name' => ['required', 'string', 'max:120'], 'last_name' => ['required', 'string', 'max:120'],
            'birth_date' => ['nullable', 'date'], 'gender' => ['nullable', 'string', 'max:32'], 'locale' => ['nullable', Rule::in(['de', 'en'])],
            'email' => ['nullable', 'email'], 'phone' => ['nullable', 'string', 'max:40'],
            'street' => ['nullable', 'string', 'max:200'], 'postal_code' => ['nullable', 'string', 'max:16'], 'city' => ['nullable', 'string', 'max:100'],
            'groups' => ['nullable', 'string', 'max:500'], 'membership_type' => ['nullable', 'string', 'max:64'],
            'guardian1_first_name' => ['nullable', 'string', 'max:100'], 'guardian1_last_name' => ['nullable', 'string', 'max:100'],
            'guardian1_email' => ['nullable', 'email'], 'guardian1_phone' => ['nullable', 'string', 'max:40'],
            'guardian2_first_name' => ['nullable', 'string', 'max:100'], 'guardian2_last_name' => ['nullable', 'string', 'max:100'],
            'guardian2_email' => ['nullable', 'email'], 'guardian2_phone' => ['nullable', 'string', 'max:40'],
            'source_id' => ['nullable', 'string', 'max:120'],
        ]);
        $importer = app(PeopleImporter::class);
        $plan = $importer->plan([$row], 'api');
        if (($plan['rows'][0]['action'] ?? '') === 'error') {
            return response()->json(['message' => 'Invalid data.', 'errors' => $plan['rows'][0]['messages']], 422);
        }
        $batch = $importer->import([$row], 'api');
        // The person of the row is the first person the batch created or updated (legal guardians follow).
        $id = DB::table('import_records')->where('import_batch_id', $batch->id)->where('model_type', Person::class)->orderBy('id')->value('model_id');
        $person = $id !== null ? Person::query()->find((int) $id) : null;

        return response()->json(['data' => $person ? Presenter::person($person->load('contactPoints', 'guardians', 'wards', 'households', 'groups', 'participations.event'), true) : null, 'import_batch' => $batch->id], 201);
    }

    /** Change name, date of birth, gender or language. */
    public function update(Request $request, string $person): JsonResponse
    {
        $this->scope('people:write');
        $model = $this->person($person);
        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:120'], 'last_name' => ['sometimes', 'string', 'max:120'],
            'birth_date' => ['sometimes', 'nullable', 'date'], 'gender' => ['sometimes', 'nullable', 'string', 'max:32'], 'locale' => ['sometimes', Rule::in(['de', 'en'])],
        ]);
        $model->fill($data)->save();

        return response()->json(['data' => Presenter::person($model)]);
    }

    /** Families with their members. */
    public function households(Request $request): JsonResponse
    {
        $this->scope('people:read');
        $page = Household::query()->with('members')->orderBy('name')
            ->when($request->string('search')->toString(), fn ($q, string $s) => $q->where('name', 'like', '%'.$s.'%'))->paginate($this->perPage());

        return response()->json(['data' => $page->getCollection()->map(fn (Household $h) => Presenter::household($h))->values(), 'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function household(string $household): JsonResponse
    {
        $this->scope('people:read');

        return response()->json(['data' => Presenter::household(Household::query()->where('public_id', $household)->with('members')->firstOrFail())]);
    }
}
