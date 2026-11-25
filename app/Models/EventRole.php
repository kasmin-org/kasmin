<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Events\Rounds;
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
 * @property list<string>|null $waitlist_order
 * @property int $price_cents
 * @property int $role_discount_cents
 * @property bool $counts_as_sibling
 * @property int|null $registration_form_id
 */
class EventRole extends Model
{
    use Auditable;

    public const KEYS = ['child', 'junior_counsellor', 'staff', 'guest'];

    /** Statuses that take a place. */
    public const OCCUPYING = ['requested', 'registered', 'confirmed', 'offered', 'attended'];

    protected $guarded = ['id'];

    protected $attributes = ['reserve_places' => 0, 'registered_by' => 'guardian', 'price_cents' => 0, 'role_discount_cents' => 0, 'counts_as_sibling' => true];

    protected function casts(): array
    {
        return ['counts_as_sibling' => 'boolean', 'waitlist_order' => 'array'];
    }

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

    /** Places somebody can take directly now: none before the open round (RR01). */
    public function directLeft(): ?int
    {
        return $this->event !== null && ! Rounds::directRegistration($this->event, $this->role_key) ? 0 : $this->bookableLeft();
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
