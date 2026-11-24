<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person taking part in an event in a role, with status and history (PA01).
 *
 * @property int $id
 * @property int $event_id
 * @property int $person_id
 * @property string $role_key
 * @property string $status
 * @property Carbon $registered_at
 * @property bool $reserve_place
 * @property Carbon|null $offer_expires_at
 * @property int|null $price_cents
 * @property string|null $price_reason
 * @property int|null $guest_of_id
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 */
class Participation extends Model
{
    use Auditable;

    public const STATUSES = ['requested', 'registered', 'confirmed', 'waitlist', 'offered', 'cancelled', 'declined', 'attended', 'no_show'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['registered_at' => 'datetime', 'reserve_place' => 'boolean', 'offer_expires_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** Position on the waitlist (1-based) by registration time, or null if not waiting. */
    public function waitlistPosition(): ?int
    {
        if ($this->status !== 'waitlist') {
            return null;
        }

        return 1 + self::query()->where('event_id', $this->event_id)->where('role_key', $this->role_key)->where('status', 'waitlist')
            ->where(fn ($q) => $q->where('registered_at', '<', $this->registered_at)
                ->orWhere(fn ($q) => $q->where('registered_at', $this->registered_at)->where('id', '<', $this->id)))
            ->count();
    }
}
