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
 * @property int $id
 * @property string $kind
 * @property int|null $event_id
 * @property array<string, mixed>|null $filter
 */
class Group extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['filter' => 'array', 'updated_members_at' => 'datetime'];
    }

    /** @return BelongsTo<Group, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'parent_id');
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<Group, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Group::class, 'parent_id');
    }

    /**
     * Current members; ended memberships stay as history (GR04).
     *
     * @return BelongsToMany<Person, $this>
     */
    public function people(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'group_memberships')->withPivot(['starts_on', 'ends_on', 'source'])->withTimestamps()
            ->where(fn ($q) => $q->whereNull('group_memberships.ends_on')->orWhereDate('group_memberships.ends_on', '>=', now()->toDateString()));
    }
}
