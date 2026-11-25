<?php

namespace App\Http\Controllers\Api;

use App\Models\Person;
use App\Support\Audit\Audit;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Legal guardians of a person. Scopes: people:read, people:write.
 */
class GuardiansController extends ApiController
{
    /** The legal guardians of a person (ended guardianships are left out). */
    public function index(string $person): JsonResponse
    {
        $this->scope('people:read');

        return $this->list($this->person($person));
    }

    /**
     * Makes a person a legal guardian of this person. Not more than the setting max_guardians allows, and not for
     * people who have reached the age at which guardianships end. Families are not changed.
     */
    public function store(Request $request, string $person): JsonResponse
    {
        $this->scope('people:write');
        $child = $this->person($person);
        $guardian = $this->person((string) $request->validate(['guardian' => ['required', 'string']])['guardian']);
        abort_if($guardian->id === $child->id, 422, 'A person cannot be its own legal guardian.');
        abort_if($child->wards()->whereKey($guardian->id)->exists(), 422, 'This person is a child of the person.');
        abort_if($child->birth_date !== null && ($child->ageAt() ?? 0) >= Settings::int('guardian_until_age'), 422, 'The person is too old for legal guardians.');

        $added = DB::transaction(function () use ($child, $guardian) {
            Person::query()->whereKey($child->id)->lockForUpdate()->firstOrFail();
            if ($child->guardians()->whereKey($guardian->id)->exists()) {
                return false;
            }
            abort_if($child->guardians()->count() >= Settings::int('max_guardians'), 422, 'The person already has the highest number of legal guardians.');
            // An ended guardianship of the same two people starts again.
            $pair = ['guardian_id' => $guardian->id, 'child_id' => $child->id];
            DB::table('guardianships')->where($pair)->exists()
                ? DB::table('guardianships')->where($pair)->update(['ends_on' => null, 'updated_at' => now()])
                : DB::table('guardianships')->insert($pair + ['created_at' => now(), 'updated_at' => now()]);
            Audit::record('guardian.added', $guardian, null, ['child_id' => $child->id, 'children' => 1]);

            return true;
        });

        return $this->list($child, $added ? 201 : 200);
    }

    /** Ends the guardianship today. The guardian stays a person and a member of its families. */
    public function destroy(string $person, string $guardian): JsonResponse
    {
        $this->scope('people:write');
        $child = $this->person($person);
        $model = $child->guardians()->where('people.public_id', $guardian)->firstOrFail();
        DB::table('guardianships')->where(['guardian_id' => $model->id, 'child_id' => $child->id])->update(['ends_on' => now()->subDay()->toDateString(), 'updated_at' => now()]);
        Audit::record('guardianship.ended', $child, null, ['reason' => 'api', 'guardian_id' => $model->id]);

        return $this->list($child);
    }

    private function list(Person $person, int $status = 200): JsonResponse
    {
        $guardians = $person->guardians()->orderBy('last_name')->orderBy('first_name')->get();

        return response()->json(['data' => $guardians->map(fn (Person $g) => ['id' => $g->public_id, 'name' => $g->fullName()])->values()], $status);
    }
}
