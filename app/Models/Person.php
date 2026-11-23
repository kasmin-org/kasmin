<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Every human being in Kasmin: child, legal guardian, staff member, alumnus, guest.
 *
 * @property int $id
 * @property string $public_id
 * @property string $first_name
 * @property string $last_name
 * @property Carbon|null $birth_date
 * @property string $locale
 */
class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use Auditable, HasFactory, HasUlids;

    protected $table = 'people';

    protected $guarded = ['id', 'public_id'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['birth_date' => 'date', 'anonymised_at' => 'datetime'];
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function ageAt(?Carbon $date = null): ?int
    {
        return $this->birth_date !== null ? (int) floor($this->birth_date->diffInYears($date ?? now(), absolute: true)) : null;
    }

    /** @return HasMany<ContactPoint, $this> */
    public function contactPoints(): HasMany
    {
        return $this->hasMany(ContactPoint::class);
    }

    /** @return HasOne<User, $this> */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /**
     * Legal guardians of this person.
     *
     * @return BelongsToMany<Person, $this>
     */
    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'guardianships', 'child_id', 'guardian_id')->withPivot(['starts_on', 'ends_on'])->withTimestamps();
    }

    /**
     * Children this person is a legal guardian of.
     *
     * @return BelongsToMany<Person, $this>
     */
    public function wards(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'guardianships', 'guardian_id', 'child_id')->withPivot(['starts_on', 'ends_on'])->withTimestamps();
    }

    /** @return BelongsToMany<Household, $this> */
    public function households(): BelongsToMany
    {
        return $this->belongsToMany(Household::class, 'household_members')->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<Participation, $this> */
    public function participations(): HasMany
    {
        return $this->hasMany(Participation::class);
    }

    /** @return BelongsToMany<Group, $this> */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_memberships')->withPivot(['starts_on', 'ends_on', 'source'])->withTimestamps();
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return HasMany<RoleAssignment, $this> */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    /** @return list<string> keys of the roles active today */
    public function activeRoleKeys(): array
    {
        $today = now()->toDateString();

        /** @var list<string> $keys */
        $keys = $this->roleAssignments()
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', $today))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->pluck('roles.key')->all();

        return $keys;
    }

    public function primaryEmail(): ?string
    {
        $email = $this->contactPoints()->where('type', 'email')->orderByDesc('is_primary')->value('value');

        return is_string($email) ? $email : null;
    }
}
