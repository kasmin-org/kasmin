<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property string $title
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $status
 * @property bool $registration_binding
 * @property Carbon|null $age_reference_date
 * @property Carbon|null $answers_until
 * @property int $max_guests
 * @property int|null $safety_confirm_days
 * @property bool $is_template
 * @property bool $public_page
 * @property Carbon|null $announce_at
 * @property Carbon|null $registration_opens_at
 * @property Carbon|null $registration_closes_at
 * @property Carbon|null $status_checked_at
 * @property Carbon|null $created_at
 * @property string|null $image_path
 * @property string $currency
 * @property int $cancellation_fee_cents
 * @property array<string, string>|null $cancellation_text
 * @property string $sibling_mode
 * @property int $sibling_amount_cents
 * @property int|null $sibling_cap_position
 * @property int $sibling_min_children
 * @property string $sibling_order
 * @property list<int>|null $reminder_days_before
 */
class Event extends Model
{
    use Auditable, HasUlids;

    public const STATUSES = ['draft', 'announced', 'open', 'closed', 'running', 'finished', 'archived'];

    protected $guarded = ['id', 'public_id'];

    /** Defaults like in the database, also before the model is reloaded. */
    protected $attributes = ['status' => 'draft', 'is_template' => false, 'public_page' => false, 'max_guests' => 0, 'registration_binding' => true, 'currency' => 'EUR', 'sibling_mode' => 'none', 'sibling_amount_cents' => 0, 'sibling_min_children' => 2, 'sibling_order' => 'oldest'];

    protected static function booted(): void
    {
        // Templates (ET01) are never shown or used as events.
        static::addGlobalScope('template', fn (Builder $query) => $query->where('events.is_template', static::class === EventTemplate::class));
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'registration_binding' => 'boolean', 'age_reference_date' => 'date', 'is_template' => 'boolean', 'public_page' => 'boolean', 'announce_at' => 'datetime', 'registration_opens_at' => 'datetime', 'registration_closes_at' => 'datetime', 'status_checked_at' => 'datetime', 'answers_until' => 'date', 'reminder_days_before' => 'array', 'cancellation_text' => 'array'];
    }

    /** @return BelongsTo<EventSeries, $this> */
    public function series(): BelongsTo
    {
        return $this->belongsTo(EventSeries::class, 'event_series_id');
    }

    /** @return HasMany<EventRole, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(EventRole::class);
    }

    /** @return HasMany<EventInstallment, $this> */
    public function installments(): HasMany
    {
        return $this->hasMany(EventInstallment::class)->orderBy('sort');
    }

    /** @return HasMany<Participation, $this> */
    public function participations(): HasMany
    {
        return $this->hasMany(Participation::class);
    }

    /** @return HasMany<EventPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(EventPrice::class)->orderBy('sort')->orderBy('id');
    }

    /** @return HasMany<EventDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(EventDocument::class)->orderBy('sort')->orderBy('id');
    }

    /** @return HasMany<RegistrationRound, $this> */
    public function rounds(): HasMany
    {
        return $this->hasMany(RegistrationRound::class)->orderBy('sort')->orderBy('id');
    }

    /** @return HasMany<EventCost, $this> */
    public function costs(): HasMany
    {
        return $this->hasMany(EventCost::class)->orderBy('sort');
    }

    /** Date at which ages are calculated (EE04, default: event start). */
    public function ageReferenceDate(): Carbon
    {
        return $this->age_reference_date ?? $this->starts_at;
    }

    public function isOpenForRegistration(): bool
    {
        return $this->status === 'open';
    }
}
