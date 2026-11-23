<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\HouseholdFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A family / household: billing and mailing unit with legal guardians and children.
 *
 * @property int $id
 * @property string $name
 */
class Household extends Model
{
    /** @use HasFactory<HouseholdFactory> */
    use Auditable, HasFactory, HasUlids;

    protected $guarded = ['id', 'public_id'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return BelongsToMany<Person, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'household_members')->withPivot('role')->withTimestamps();
    }

    /** @return BelongsToMany<Person, $this> */
    public function guardians(): BelongsToMany
    {
        return $this->members()->wherePivot('role', 'guardian');
    }

    /** @return BelongsToMany<Person, $this> */
    public function children(): BelongsToMany
    {
        return $this->members()->wherePivot('role', 'child');
    }

    /** @return HasMany<HouseholdEmergencyContact, $this> */
    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(HouseholdEmergencyContact::class)->orderBy('sort');
    }

    /** @return BelongsTo<Person, $this> */
    public function invoiceRecipient(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'invoice_recipient_id');
    }
}
