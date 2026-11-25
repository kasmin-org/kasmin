<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Events\Waitlist;
use App\Support\Settings;
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
 * @property int|null $registered_by_id
 * @property Carbon $registered_at
 * @property bool $reserve_place
 * @property Carbon|null $offer_expires_at
 * @property int|null $price_cents
 * @property string|null $price_reason
 * @property int|null $guest_of_id
 * @property string|null $offer_mode
 * @property int|null $notified_position
 * @property list<string>|null $waitlist_flags
 * @property string|null $waitlist_note
 * @property Carbon|null $skipped_at
 * @property string|null $skip_reason
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
        return ['registered_at' => 'datetime', 'reserve_place' => 'boolean', 'offer_expires_at' => 'datetime', 'cancelled_at' => 'datetime', 'waitlist_flags' => 'array', 'skipped_at' => 'datetime'];
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

    /** Registered by the person itself, not by a legal guardian. */
    public function isOwn(): bool
    {
        return $this->registered_by_id !== null && $this->registered_by_id === $this->person_id;
    }

    /** What a child registers for itself is not shown to its legal guardians, unless the organisation allows it (PE05a). */
    public function visibleInPortal(Person $viewer): bool
    {
        return ! $this->isOwn() || $viewer->id === $this->person_id || Settings::get('own_registrations') === 'visible';
    }

    /** Position on the waitlist (1-based) by the rules of the role, or null if not waiting (WL02). */
    public function waitlistPosition(): ?int
    {
        return $this->status !== 'waitlist' ? null : app(Waitlist::class)->position($this);
    }
}
