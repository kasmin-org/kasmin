<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Group of people; a person can be in any number of groups at the same time (GR01).
 *
 * @property string $name
 * @property string $kind
 */
class Group extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['filter' => 'array'];
    }

    /** @return BelongsTo<Group, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'parent_id');
    }

    /** @return HasMany<Group, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Group::class, 'parent_id');
    }

    /** @return BelongsToMany<Person, $this> */
    public function people(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'group_memberships')->withPivot(['starts_on', 'ends_on', 'source'])->withTimestamps();
    }
}
