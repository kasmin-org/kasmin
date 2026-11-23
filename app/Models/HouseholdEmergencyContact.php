<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Further emergency contact of a family, shown on every child's safety sheet (FD01, FD02, SF11). */
class HouseholdEmergencyContact extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}
