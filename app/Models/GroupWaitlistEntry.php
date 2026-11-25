<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person on the waitlist of a group (WL01).
 *
 * @property int $id
 * @property int $group_id
 * @property int $person_id
 * @property string $status
 * @property Carbon $listed_at
 * @property list<string>|null $waitlist_flags
 * @property string|null $waitlist_note
 * @property Carbon|null $skipped_at
 * @property string|null $skip_reason
 * @property Carbon|null $offer_expires_at
 */
class GroupWaitlistEntry extends Model
{
    use Auditable;

    public const ACTIVE = ['waiting', 'offered'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'waiting'];

    protected function casts(): array
    {
        return ['listed_at' => 'datetime', 'waitlist_flags' => 'array', 'skipped_at' => 'datetime', 'offer_expires_at' => 'datetime'];
    }

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
