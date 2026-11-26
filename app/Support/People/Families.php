<?php

namespace App\Support\People;

use App\Models\Household;
use Illuminate\Support\Facades\DB;

/**
 * Families and guardianships (RG07). Used by the admin interface and the REST API.
 */
class Families
{
    /** Legal guardians of the family are the guardians of its children; an ended guardianship stays ended. */
    public function linkGuardians(Household $household): void
    {
        foreach ($household->children()->get() as $child) {
            foreach ($household->guardians()->pluck('people.id') as $guardian) {
                DB::table('guardianships')->insertOrIgnore(['guardian_id' => $guardian, 'child_id' => $child->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
}
