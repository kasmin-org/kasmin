<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Invitation of a further legal guardian (RG07).
 *
 * @property int $id
 * @property int $household_id
 * @property int|null $invited_by_id
 * @property array<string, string>|null $data
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 */
class GuardianInvitation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'encrypted:array', 'expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'invited_by_id');
    }
}
