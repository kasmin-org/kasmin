<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
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
 * @property string $currency
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
    protected $attributes = ['status' => 'draft', 'registration_binding' => true, 'currency' => 'EUR', 'sibling_mode' => 'none', 'sibling_amount_cents' => 0, 'sibling_min_children' => 2, 'sibling_order' => 'oldest'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'registration_binding' => 'boolean', 'age_reference_date' => 'date', 'reminder_days_before' => 'array'];
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

    /** Date at which ages are calculated (EE04, default: event start). */
    /** @return HasMany<EventCost, $this> */
    public function costs(): HasMany
    {
        return $this->hasMany(EventCost::class)->orderBy('sort');
    }

    public function ageReferenceDate(): Carbon
    {
        return $this->age_reference_date ?? $this->starts_at;
    }

    public function isOpenForRegistration(): bool
    {
        return $this->status === 'open';
    }
}
