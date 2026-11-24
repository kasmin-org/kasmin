<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Who gets places of a role, and when (RR01).
 *
 * @property int $id
 * @property int $event_id
 * @property string $role_key
 * @property string $kind
 * @property string $mode
 * @property Carbon|null $opens_at
 * @property Carbon|null $closes_at
 * @property int $min_events
 * @property int|null $group_id
 */
class RegistrationRound extends Model
{
    use Auditable;

    public const KINDS = ['returning', 'selection', 'waitlist', 'open'];

    protected $guarded = ['id'];

    protected $attributes = ['mode' => 'accept', 'min_events' => 0];

    protected function casts(): array
    {
        return ['opens_at' => 'datetime', 'closes_at' => 'datetime'];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function started(): bool
    {
        return $this->opens_at === null || $this->opens_at->isPast();
    }

    public function active(): bool
    {
        return $this->started() && ($this->closes_at === null || $this->closes_at->isFuture());
    }
}
