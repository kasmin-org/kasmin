<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Capacity, reserve places and age rule of one participant role in an event (EE03, EE04, WL04).
 *
 * @property int $event_id
 * @property string $role_key
 * @property int|null $capacity
 * @property int $reserve_places
 * @property int|null $min_age
 * @property int|null $max_age
 * @property string $registered_by
 */
class EventRole extends Model
{
    use Auditable;

    public const KEYS = ['child', 'junior_counsellor', 'staff', 'guest'];

    /** Statuses that take a place. */
    public const OCCUPYING = ['requested', 'registered', 'confirmed', 'offered', 'attended'];

    protected $guarded = ['id'];

    protected $attributes = ['reserve_places' => 0, 'registered_by' => 'guardian'];

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function occupied(): int
    {
        return Participation::query()->where('event_id', $this->event_id)->where('role_key', $this->role_key)
            ->whereIn('status', self::OCCUPYING)->count();
    }

    /** Places that can still be booked before the waitlist starts (capacity − reserve − occupied). */
    public function bookableLeft(): ?int
    {
        if ($this->capacity === null) {
            return null;
        }
        $reserveUsed = Participation::query()->where('event_id', $this->event_id)->where('role_key', $this->role_key)
            ->whereIn('status', self::OCCUPYING)->where('reserve_place', true)->count();
        $regular = $this->occupied() - $reserveUsed;

        return max(0, $this->capacity - $this->reserve_places - $regular);
    }

    public function reserveLeft(): int
    {
        $used = Participation::query()->where('event_id', $this->event_id)->where('role_key', $this->role_key)
            ->whereIn('status', self::OCCUPYING)->where('reserve_place', true)->count();

        return max(0, $this->reserve_places - $used);
    }

    public function waitlistLength(): int
    {
        return Participation::query()->where('event_id', $this->event_id)->where('role_key', $this->role_key)->where('status', 'waitlist')->count();
    }

    public function allowsAge(?int $age): bool
    {
        if ($age === null) {
            return $this->min_age === null && $this->max_age === null;
        }

        return ($this->min_age === null || $age >= $this->min_age) && ($this->max_age === null || $age <= $this->max_age);
    }
}
